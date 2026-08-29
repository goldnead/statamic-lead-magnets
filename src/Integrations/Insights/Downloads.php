<?php

namespace Goldnead\LeadMagnets\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many times a file was actually handed over.
 *
 * Over `lead_magnet_downloads`, which is one row per redemption — not the
 * `download_count` counter on the grant. The counter is a running total with no
 * dates on it and cannot be windowed at all; the rows can, which is why they
 * exist.
 *
 * Downloads, not downloaders: somebody who fetches the same PDF three times is
 * three rows here and one address next door. That is what the number is for —
 * it is the load on the disk and the measure of whether people came back to the
 * file, and collapsing it to distinct grants would answer a question
 * {@see Confirmed} already answers.
 *
 * `downloaded_at` is nullable in the schema and the base class refuses the
 * nulls. A row without one is a redemption nobody dated, and dating it by its
 * `created_at` would be inventing the fact rather than reporting it.
 */
class Downloads extends LeadMagnetMetric
{
    protected function table(): string
    {
        return 'lead_magnet_downloads';
    }

    protected function timestamp(): string
    {
        return 'downloaded_at';
    }

    public function handle(): string
    {
        return 'lead_magnets.downloads';
    }

    public function label(): string
    {
        return __('lead-magnets::insights.metric_downloads');
    }

    public function description(): ?string
    {
        return __('lead-magnets::insights.metric_downloads_description');
    }

    public function unit(): string
    {
        return Unit::COUNT;
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
