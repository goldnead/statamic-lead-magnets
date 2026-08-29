<?php

namespace Goldnead\LeadMagnets\Integrations\Insights;

use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Facades\DB;

/**
 * How many freebies were asked for.
 *
 * On `requested_at`, which is the only date on the row that means "somebody
 * filled in the form". `created_at` is when the row first appeared, and for a
 * returning address that was months ago.
 *
 * **One row per address and resource, and `requested_at` is overwritten.** The
 * unique index is `(brand_id, resource_id, email)` and `GrantService::request()`
 * updates the row it finds rather than writing a second one — asking twice is a
 * repeat, not a new lead. The consequence has to be said out loud because it is
 * invisible on the screen: this figure counts grants **last** requested in the
 * window. Somebody who asked in June and again in August is one row, dated
 * August, and June's report loses them retroactively. It is the honest reading
 * of a table that keeps current state rather than a log — the alternative
 * would be to invent a request history this addon does not store.
 *
 * The split is by resource, and the label is the resource's title rather than
 * its id: `4` is a number a reader cannot act on, and the id is what goes in
 * the URL anyway. A resource that has since been deleted keeps its id as its
 * label rather than dropping out of the report — the requests happened.
 */
class Requested extends LeadMagnetMetric implements HasBreakdowns
{
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
        return 'lead_magnets.requested';
    }

    public function label(): string
    {
        return __('lead-magnets::insights.metric_requested');
    }

    public function description(): ?string
    {
        return __('lead-magnets::insights.metric_requested_description');
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

    public function breakdowns(): array
    {
        return ['resource' => __('lead-magnets::insights.metric_breakdown_resource')];
    }

    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if (! $this->available() || $dimension !== 'resource') {
            return [];
        }

        $rows = $this->splitByColumn($this->untilNow($query), $query, 'resource_id', 'count(*)', $limit);

        $titel = $this->titlesFor(array_filter(array_column($rows, 'key')));

        return array_map(fn (array $row) => [
            'key' => $row['key'],
            'label' => $row['key'] === null
                ? $this->missingLabel($dimension)
                : ($titel[$row['key']] ?? $row['key']),
            'value' => $row['value'],
        ], $rows);
    }

    /**
     * The names of the resources in this split, in one query.
     *
     * Read straight from the table rather than through {@see Resource}, and it
     * stays that way now that the figure itself narrows by brand. The split's
     * keys are already this brand's resources; what the model's `HasBrand` scope
     * would still cost is a *soft-deleted or reassigned* resource losing its
     * title and falling back to a numeric id in the middle of an otherwise
     * readable list. A lookup that only ever turns ids into words does not need
     * a scope to protect it: it reads two columns and shows nothing that the
     * figure beside it does not already count.
     *
     * @param  array<int, string>  $ids
     * @return array<string, string>
     */
    protected function titlesFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table((new Resource)->getTable())
            ->whereIn('id', $ids)
            ->select('id', 'title')
            ->get();

        $titel = [];

        foreach ($rows as $row) {
            $name = (string) $row->title;

            // An untitled resource keeps its id rather than being labelled with
            // an empty cell, which reads as a broken report.
            if ($name !== '') {
                $titel[(string) $row->id] = $name;
            }
        }

        return $titel;
    }
}
