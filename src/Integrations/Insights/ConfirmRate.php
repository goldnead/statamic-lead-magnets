<?php

namespace Goldnead\LeadMagnets\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Carbon;

/**
 * How many of the people who asked came back and confirmed.
 *
 * **Null, never nought per cent.** A period in which nobody asked has no
 * confirmation rate — there is nothing to be a fraction of. Printing 0 % would
 * put a number on the screen next to a request count of zero that contradicts
 * it, and a reader would take it for a very bad month rather than for an empty
 * one. That distinction is the contract's, stated in {@see Metric::value()},
 * and it applies per bucket as much as to the headline: a day with no requests
 * is `null` in the series and draws no bar, rather than being left out and
 * filled in as a confident zero.
 *
 * ## Both halves keep their own dates, and the rate is about a period
 *
 * Requests are counted on `requested_at`, confirmations on the moment access
 * opened. Somebody who asked on the last evening of the month and confirmed the
 * next morning is in one month's denominator and the next month's numerator. So
 * this is a rate about a **period's traffic**, not about a cohort — "of the
 * people who asked in August, how many confirmed" would mean following those
 * requests forward through time and re-stating August's figure every day
 * afterwards.
 *
 * Two consequences follow, and both are correct rather than bugs:
 *
 *  - **It can exceed 100 %.** A quiet week with a backlog of confirmations from
 *    the week before will do it.
 *  - **It moves when nobody requests anything.** A day with three confirmations
 *    and no requests has no rate at all.
 *
 * The two counts are taken from {@see Requested} and {@see Confirmed}
 * themselves rather than re-queried here. They sit on the same screen as this
 * one, and a rate whose numerator disagrees with the tile next to it is worse
 * than no rate: it makes the reader distrust all three.
 */
class ConfirmRate extends LeadMagnetMetric
{
    /**
     * A hard stop on how many buckets a chart may have.
     *
     * Only reachable through an open-ended period asked at daily grain, which
     * Insights itself never does — it switches to months past a quarter. The
     * recent end is kept, because that is the end anybody reading a rate cares
     * about.
     */
    private const MAX_BUCKETS = 1200;

    protected Requested $angefragt;

    protected Confirmed $bestaetigt;

    public function __construct()
    {
        $this->angefragt = new Requested;
        $this->bestaetigt = new Confirmed;
    }

    /** The denominator's table. The numerator lives elsewhere; see {@see Confirmed}. */
    protected function table(): string
    {
        return 'lead_magnet_grants';
    }

    protected function timestamp(): string
    {
        return 'requested_at';
    }

    public function handle(): string
    {
        return 'lead_magnets.confirm_rate';
    }

    public function label(): string
    {
        return __('lead-magnets::insights.metric_confirm_rate');
    }

    public function description(): ?string
    {
        return __('lead-magnets::insights.metric_confirm_rate_description');
    }

    public function unit(): string
    {
        return Unit::PERCENT;
    }

    /** Both halves have to be answerable, or the fraction is not a number. */
    public function available(): bool
    {
        return $this->angefragt->available() && $this->bestaetigt->available();
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        $nenner = (int) $this->angefragt->value($query);

        if ($nenner <= 0) {
            return null;
        }

        // One decimal. A confirmation rate is read to compare weeks, and
        // "62.9518 %" asserts a precision that forty requests cannot carry.
        return round((int) $this->bestaetigt->value($query) / $nenner * 100, 1);
    }

    /**
     * A rate per bucket, and an explicit null where there is nothing to divide
     * by.
     *
     * Every bucket in the period is named, which is unlike the counts beside it
     * and is the whole point: an omitted bucket is filled in by Insights as a
     * zero, and a zero per cent on a day nobody asked is exactly the false
     * statement this metric exists to avoid. `null` reaches the screen intact
     * and draws no bar.
     *
     * @return array<string, float|null>
     */
    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        [$from, $end] = $this->bounds($query);

        $angefragt = $this->angefragt->series($query);
        $bestaetigt = $this->bestaetigt->series($query);

        // A period in which nothing at all happened gets no series, rather than
        // thirty-one nulls. Both are drawn identically — a null draws no bar —
        // and the empty array says the same thing without asking the screen to
        // carry a month of blanks.
        if ($angefragt === [] && $bestaetigt === []) {
            return [];
        }

        $start = $from ?? $this->earliestBucketStart($angefragt, $bestaetigt);

        if ($start === null || $start->greaterThan($end)) {
            return [];
        }

        $reihe = [];

        foreach ($this->bucketKeys($start, $end, $query) as $bucket) {
            $nenner = (int) ($angefragt[$bucket] ?? 0);

            $reihe[$bucket] = $nenner <= 0
                ? null
                : round((int) ($bestaetigt[$bucket] ?? 0) / $nenner * 100, 1);
        }

        return $reihe;
    }

    /**
     * Where an open-ended chart should begin.
     *
     * The earliest bucket either half has anything in. Starting at the epoch
     * would produce a decade of nulls and a chart nobody can read.
     *
     * @param  array<string, int>  $angefragt
     * @param  array<string, int>  $bestaetigt
     */
    protected function earliestBucketStart(array $angefragt, array $bestaetigt): ?Carbon
    {
        $buckets = array_keys($angefragt + $bestaetigt);

        if ($buckets === []) {
            return null;
        }

        sort($buckets);

        // A day key parses as itself; a month key parses as its first day,
        // which is where that bucket begins.
        return Carbon::parse($buckets[0]);
    }

    /**
     * Every bucket key the period covers, in order.
     *
     * @return array<int, string>
     */
    protected function bucketKeys(Carbon $start, Carbon $end, MetricQuery $query): array
    {
        $monthly = $query->bucket === MetricQuery::BUCKET_MONTH;

        $cursor = $monthly ? $start->copy()->startOfMonth() : $start->copy()->startOfDay();
        $last = $monthly ? $end->copy()->startOfMonth() : $end->copy()->startOfDay();

        $keys = [];

        while ($cursor->lessThanOrEqualTo($last)) {
            $keys[] = $cursor->format($monthly ? 'Y-m' : 'Y-m-d');
            $monthly ? $cursor->addMonth() : $cursor->addDay();
        }

        return count($keys) > self::MAX_BUCKETS
            ? array_slice($keys, -self::MAX_BUCKETS)
            : $keys;
    }
}
