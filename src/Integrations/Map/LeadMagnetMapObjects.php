<?php

namespace Goldnead\LeadMagnets\Integrations\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapEdges;
use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapEdge;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\LeadMagnets\Integrations\Insights\Requested;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Illuminate\Support\Carbon;

/**
 * The lead magnets as objects a funnels map can point at.
 *
 * The map keeps `type` and `id` and asks here for everything else. The id is the
 * resource's handle, the same key the public form and the URLs use, so a map
 * reference and a form never disagree about which resource is meant.
 *
 * The metric is the figure the insights addon calls "Requested"
 * ({@see Requested}), narrowed to one
 * resource. The rule is the same one: a grant counts on `requested_at`, only
 * inside the period, never past now, and only for the current brand. The
 * timestamp is restated in the application zone, because the column is written
 * with `now()` in that zone. The grant model's brand scope does the brand part,
 * as the metric's own brand filter does in the insights addon.
 */
class LeadMagnetMapObjects implements ProvidesMapEdges, ProvidesMapObjects
{
    public const TYPE = 'lead_magnet';

    public function type(): string
    {
        return self::TYPE;
    }

    public function typeLabel(): string
    {
        return __('lead-magnets::resources.map_type_label');
    }

    /** @return array<int, MapObject> */
    public function objects(?string $query = null, int $limit = 50): array
    {
        $rows = Resource::query()
            ->orderBy('title')
            ->orderBy('id')
            ->limit(max(1, $limit));

        $needle = trim((string) $query);

        if ($needle !== '') {
            $pattern = '%'.$this->escapeLike($needle).'%';

            // `!` is the escape character: SQLite has no default one, and MySQL's
            // backslash would have to be doubled inside the SQL string. The
            // pattern itself is escaped above, so a `%` or `_` typed by an
            // editor matches itself and not every row.
            $rows->where(fn ($match) => $match
                ->whereRaw("title LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("handle LIKE ? ESCAPE '!'", [$pattern]));
        }

        return $rows->get()
            ->map(fn (Resource $resource) => $this->toObject($resource))
            ->values()
            ->all();
    }

    public function find(string $id): ?MapObject
    {
        $resource = $this->resolve($id);

        return $resource ? $this->toObject($resource) : null;
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        $resource = $this->resolve($id);

        if (! $resource) {
            return null;
        }

        $zone = date_default_timezone_get();

        $count = Grant::query()
            ->where('resource_id', $resource->id)
            ->whereNotNull('requested_at')
            ->where('requested_at', '>=', $period->from->setTimezone($zone))
            ->where('requested_at', '<', $period->to->setTimezone($zone))
            ->where('requested_at', '<=', Carbon::now($zone))
            ->count();

        return new MapMetric((int) $count, __('lead-magnets::insights.map_requests'), $period);
    }

    /**
     * The page each lead magnet's sign-up form stands on, one edge per resource.
     *
     * Only a resource that names a page yields an edge; the edge is read from
     * `page_entry` and nothing else. The brand scope of the model keeps it to
     * the current brand, like every other read here.
     *
     * @return iterable<int, MapEdge>
     */
    public function edges(): iterable
    {
        $resources = Resource::query()
            ->whereNotNull('page_entry')
            ->where('page_entry', '!=', '')
            ->orderBy('id')
            ->get();

        foreach ($resources as $resource) {
            yield new MapEdge(
                fromType: 'entry',
                fromId: (string) $resource->page_entry,
                toType: self::TYPE,
                toId: $this->idOf($resource),
                kind: 'collects',
                label: __('lead-magnets::resources.map_edge_collects'),
                derivedFrom: 'field `page` of the lead magnet',
            );
        }
    }

    /**
     * The handle, or the id for a resource that somehow has none.
     *
     * The handle column is required and unique, but the Control Panel derives it
     * from the title and a title with no letters in it slugs to nothing. Such a
     * resource still needs an address the map can store.
     */
    protected function idOf(Resource $resource): string
    {
        return $resource->handle !== '' ? (string) $resource->handle : (string) $resource->id;
    }

    protected function toObject(Resource $resource): MapObject
    {
        return new MapObject(
            type: self::TYPE,
            id: $this->idOf($resource),
            label: $resource->title !== '' ? (string) $resource->title : $this->idOf($resource),
            cpUrl: cp_route('lead-magnets.resources.show', $resource->id),
            enabled: (bool) $resource->published,
            detail: match (true) {
                $resource->isLink() => __('lead-magnets::resources.delivery_link'),
                $resource->isAccess() => __('lead-magnets::resources.delivery_access'),
                default => __('lead-magnets::resources.delivery_file'),
            },
        );
    }

    /** The resource behind a map id, or null. Brand-scoped like every other read here. */
    protected function resolve(string $id): ?Resource
    {
        $resource = Resource::query()->where('handle', $id)->first();

        // The fallback for a resource without a handle, whose id is what
        // {@see idOf()} stored. Only an empty handle can match here.
        if ($resource === null && ctype_digit($id)) {
            $resource = Resource::query()->where('handle', '')->whereKey((int) $id)->first();
        }

        return $resource;
    }

    /** Escapes the LIKE wildcards for the `!` escape character set in {@see objects()}. */
    protected function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
