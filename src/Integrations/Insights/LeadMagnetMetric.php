<?php

namespace Goldnead\LeadMagnets\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\TableMetric;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * What every figure this addon offers the analytics addon has in common.
 *
 * The coupling runs one way and is optional in both directions:
 * `goldnead/statamic-insights` is a `suggest`, never a `require`, and the
 * classes here are only ever loaded once the service provider has seen the
 * sibling's facade. Loading this file *means* the sibling is installed —
 * {@see TableMetric} lives in its package.
 *
 * ## Three tables, and one of them belongs to somebody else
 *
 * A request lives in `lead_magnet_grants`, a download in
 * `lead_magnet_downloads`, and a **confirmation lives nowhere in this package
 * at all**. Up to 1.x the grant carried its own `confirmed_at`; the migration
 * `2026_08_03_000002_drop_legacy_grant_state_from_lead_magnet_grants_table`
 * removed it along with the rest of the duplicated lifecycle, because access
 * state moved to `goldnead/statamic-entitlements` — one state machine for the
 * platform instead of one per addon. So {@see Confirmed} reads the
 * entitlements table, which is a hard `require` of this package and whose model
 * this package already uses in its own `Grant`. It is not a reach across an
 * optional boundary; it is where the fact now lives.
 *
 * ## The two tables keep time differently, and that is not a mistake
 *
 * `lead_magnet_grants` and `lead_magnet_downloads` use Laravel's ordinary
 * `datetime` cast, which stores whatever Carbon it is handed **in that value's
 * own zone** — and everything writing here writes `now()`, so those columns are
 * in the application timezone. `entitlements` deliberately does not: it stores
 * UTC unconditionally, because each of its columns decides whether a paying
 * customer gets in and a two-hour slip is not a formatting bug.
 *
 * Insights hands down a period built from `Carbon::now()`, in the application
 * timezone. So each metric names the zone its own column is kept in
 * ({@see zone()}) and the window is restated in that zone before it touches a
 * query ({@see shifted()}). Converting both halves, or neither, would put one of
 * the two figures out by the site's offset — and a confirmation rate whose
 * numerator and denominator are measured over different hours is wrong in a way
 * that looks entirely plausible.
 *
 * That restatement is the **whole** of what this package adds to the window.
 * Everything else — a row with no timestamp is in no period, the upper bound is
 * half-open because a binding truncates the fraction of a second, the clamp on
 * a future date, the brand — is {@see TableMetric}'s and arrives here by
 * inheritance. It was a copy once, and the copy sat three repairs behind the
 * original with a green suite over it the entire time.
 *
 * ## Everything here stops at the brand boundary
 *
 * All three tables carry `brand_id`, and {@see brandColumn()} declares it so
 * that the base class narrows the figure, the chart and every split at once, by
 * the same rules the rest of the install reads these tables with. It was the
 * other way round once, on the grounds that Insights asks its question per
 * installation — but these tiles sit beside tiles that do narrow, and a figure
 * that quietly counts another customer's requests is a leak rather than a wider
 * view. On a single-brand install, which is most of them, this adds no
 * condition at all.
 */
abstract class LeadMagnetMetric extends TableMetric
{
    public function group(): string
    {
        return __('lead-magnets::insights.metric_group');
    }

    /**
     * The table has to be there, and the bridge has to be wanted.
     *
     * `lead-magnets.integrations.insights` sits beside the switches for the CRM,
     * the mailing list and the suppression list, and behaves the same way: off
     * means this addon offers nothing, which is different from offering a zero.
     */
    public function available(): bool
    {
        return (bool) config('lead-magnets.integrations.insights', true) && parent::available();
    }

    /**
     * Which brand a row belongs to.
     *
     * The same column on all three tables, and the same one on the sibling's:
     * declaring it here narrows every figure in the directory at once, so no
     * single metric can be the one that forgot.
     */
    protected function brandColumn(): ?string
    {
        return 'brand_id';
    }

    /**
     * The zone the column this metric counts on is kept in.
     *
     * Read by {@see TableMetric::untilNow()} to close the far end of the window
     * on the same clock the column was written on. Stating it is the whole job;
     * the clamp itself is the base class's, and used to be restated here.
     *
     * The application timezone for this package's own tables: they use
     * Laravel's ordinary `datetime` cast and everything writing them writes
     * `now()`. {@see Confirmed} overrides it, because the column it counts
     * belongs to `statamic-entitlements` and is UTC on disk unconditionally.
     *
     * One method rather than two window builders, so the numerator and the
     * denominator of the confirmation rate cannot drift apart: each states its
     * own zone and everything else about the window is shared.
     */
    protected function zone(): string
    {
        return date_default_timezone_get();
    }

    /**
     * The window as this package's own columns keep it, never open at the far
     * end.
     *
     * The start may be absent — "all time" genuinely has no beginning. The end
     * may not: `confirm_expires_at` and an entitlement's `expires_at` are
     * routinely in the future, and an unbounded upper edge invites a figure to
     * count something that has not happened yet.
     *
     * Kept as its own accessor because {@see ConfirmRate} needs the two instants
     * themselves rather than a query: it names every bucket in the period,
     * including the ones neither half has anything in.
     *
     * @return array{0: ?Carbon, 1: Carbon}
     */
    protected function bounds(MetricQuery $query): array
    {
        $zone = $this->zone();

        return [
            $query->period->from?->copy()->setTimezone($zone),
            ($query->period->to ?? Carbon::now())->copy()->setTimezone($zone),
        ];
    }

    /**
     * The same question, with its window stated in this metric's own zone.
     *
     * A binding is formatted in the zone its own Carbon carries, so a bound
     * restated in the column's zone compares against that column correctly. The
     * instant does not move; only its spelling changes.
     *
     * For this package's own tables that is normally a no-op — Insights builds
     * its period from `Carbon::now()` in the same zone — and it earns its keep
     * for {@see Confirmed}, whose column is UTC, and for any caller that phrased
     * its range somewhere else entirely.
     *
     * An open-ended period has no bounds to restate and is handed on as it came.
     */
    protected function shifted(MetricQuery $query): MetricQuery
    {
        if ($query->period->from === null || $query->period->to === null) {
            return $query;
        }

        $zone = $this->zone();

        return new MetricQuery(
            Period::between(
                $query->period->from->copy()->setTimezone($zone),
                $query->period->to->copy()->setTimezone($zone),
            ),
            $query->bucket,
            $query->filters,
        );
    }

    /**
     * The rows inside the window.
     *
     * Extended, not rewritten: the zone is restated and everything else is
     * {@see TableMetric::inPeriod()}'s. That matters most for the two conditions
     * this file used to carry itself — every timestamp counted here is nullable
     * and the widest period passes no bounds to hide it, and the upper bound has
     * to be half-open because a binding drops the fraction of a second and would
     * otherwise throw away every row in the period's last second.
     */
    protected function inPeriod(MetricQuery $query, ?string $column = null): Builder
    {
        return parent::inPeriod($this->shifted($query), $column);
    }

    /** What to call a row that has no value in the dimension it is split by. */
    protected function missingLabel(string $dimension): string
    {
        return __('lead-magnets::insights.metric_no_'.$dimension);
    }
}
