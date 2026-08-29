<?php

namespace Goldnead\LeadMagnets\Integrations\Insights;

use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\LeadMagnets\Support\LeadMagnetSubject;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * How many people confirmed.
 *
 * ## Why this reads another package's table
 *
 * `lead_magnet_grants.confirmed_at` no longer exists. It was dropped, together
 * with `state`, `revoked_at` and `expires_at`, by
 * `2026_08_03_000002_drop_legacy_grant_state_from_lead_magnet_grants_table`
 * when access state moved to `goldnead/statamic-entitlements` — one state
 * machine for the platform instead of one per addon. Confirming a lead magnet
 * *is* claiming a pending entitlement: `EntitlementManager::claimPending()`
 * flips the status to active and stamps `starts_at`, atomically, and that stamp
 * is the only record of the moment anywhere.
 *
 * So the figure is `entitlements.starts_at`, over the rows this addon wrote.
 * Entitlements is a hard `require` here and its model is already used by this
 * package's own `Grant`; the table is not a reach across an optional boundary.
 *
 * ## Over the source, not over the join
 *
 * The obvious query joins `lead_magnet_grants.entitlement_id`. It was rejected,
 * and the reason is a real one rather than a stylistic preference:
 * `GrantService::reopen()` writes a **new** entitlement when an expired grant is
 * requested again, and repoints the grant at it. The earlier entitlement is
 * still in the table, still a true record of an access period that happened —
 * but nothing links to it any more. A join would therefore lose that
 * confirmation from the month it happened in, retroactively, the moment
 * somebody asked for the same freebie a second time. Filtering on the source
 * this addon stamps on every entitlement it creates keeps both.
 *
 * The price is that an installation which changes
 * `lead-magnets.entitlements.source` stops counting what it wrote under the old
 * one. That is a configuration change to a value the unique index already
 * depends on, and it is visible; a silently shrinking historical figure is not.
 *
 * ## Timezone
 *
 * This is the one query in the directory whose column is UTC on disk rather
 * than in the application timezone — see the base class. That is the whole of
 * what this class says about time: {@see zone()} names UTC and the shared
 * window builder does the rest, so the numerator and the denominator of the
 * confirmation rate are measured over the same hours by construction.
 */
class Confirmed extends LeadMagnetMetric
{
    protected function table(): string
    {
        return (new Entitlement)->getTable();
    }

    protected function timestamp(): string
    {
        return 'starts_at';
    }

    public function handle(): string
    {
        return 'lead_magnets.confirmed';
    }

    public function label(): string
    {
        return __('lead-magnets::insights.metric_confirmed');
    }

    public function description(): ?string
    {
        return __('lead-magnets::insights.metric_confirmed_description');
    }

    public function unit(): string
    {
        return Unit::COUNT;
    }

    /**
     * The grants table has to be there too.
     *
     * Not because this query touches it, but because an entitlements table with
     * no lead magnets beside it belongs to a different addon entirely, and
     * counting its rows would put somebody else's numbers under this heading.
     */
    public function available(): bool
    {
        return parent::available() && Schema::hasTable('lead_magnet_grants');
    }

    /**
     * The column here is UTC on disk, unlike the other three.
     *
     * `entitlements` stores UTC unconditionally, because each of its columns
     * decides whether a paying customer gets in. Naming the zone is all this
     * class has to do about it: the window is restated in it, and the clamp on
     * "now" with it.
     */
    protected function zone(): string
    {
        return 'UTC';
    }

    /**
     * Entitlements this addon wrote, whose access opened inside the window.
     *
     * The window is the shared one; the only condition added here is the source,
     * and it is added at this level so the figure and the chart cannot disagree
     * about which rows belong to this addon.
     *
     * `starts_at` is null for as long as a grant is pending, so the
     * `whereNotNull` the base class adds is what separates "confirmed" from
     * "asked and never came back" — and it is load-bearing on the open-ended
     * period, which passes no bounds to hide it.
     */
    protected function inPeriod(MetricQuery $query, ?string $column = null): Builder
    {
        return parent::inPeriod($query, $column)
            ->where($this->table().'.source', LeadMagnetSubject::source());
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        return (int) $this->untilNow($query)->count();
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->untilNow($query), $query, 'count(*)'),
        );
    }
}
