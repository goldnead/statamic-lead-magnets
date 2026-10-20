<?php

namespace Goldnead\LeadMagnets\Integrations\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapEdges;
use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapEdge;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\LeadMagnets\Integrations\Insights\Requested;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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

    /** Shared across instances: the registry resolves its own instance of this class. */
    protected static bool $collectingCourses = false;

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

        return new MapMetric($this->requestCount($resource, $period), __('lead-magnets::insights.map_requests'), $period);
    }

    /**
     * The detail panel (brand-context 1.19, found by method_exists): one facts
     * block with the delivery type, the requests in the period (the card's own
     * count) and the target, a course or a file. A row whose source is missing
     * is left out, never filled in.
     *
     * @return list<array<string, mixed>>|null
     */
    public function details(string $id, MapPeriod $period): ?array
    {
        $resource = $this->resolve($id);

        if (! $resource) {
            return null;
        }

        $rows = [
            ['label' => __('lead-magnets::resources.map_detail_delivery'), 'value' => $this->deliveryLabel($resource), 'href' => null],
            ['label' => __('lead-magnets::insights.map_requests'), 'value' => (string) $this->requestCount($resource, $period), 'href' => null],
        ];

        foreach ($this->targetRows($resource) as $row) {
            $rows[] = $row;
        }

        return [['kind' => 'facts', 'title' => null, 'rows' => $rows]];
    }

    /**
     * Who may open the detail panel (brand-context 1.20 asks before details()):
     * the permission that guards lead magnets in the Control Panel. Without a
     * user, no.
     */
    public function detailsAuthorized(string $id, $user): bool
    {
        return $user !== null && (bool) $user->can('manage lead magnets');
    }

    /**
     * The grants requested in the period: the figure on the card and in the
     * detail panel, counted here and nowhere else.
     */
    protected function requestCount(Resource $resource, MapPeriod $period): int
    {
        $zone = date_default_timezone_get();

        return (int) Grant::query()
            ->where('resource_id', $resource->id)
            ->whereNotNull('requested_at')
            ->where('requested_at', '>=', $period->from->setTimezone($zone))
            ->where('requested_at', '<', $period->to->setTimezone($zone))
            ->where('requested_at', '<=', Carbon::now($zone))
            ->count();
    }

    protected function deliveryLabel(Resource $resource): string
    {
        return match (true) {
            $resource->isLink() => __('lead-magnets::resources.delivery_link'),
            $resource->isAccess() => __('lead-magnets::resources.delivery_access'),
            default => __('lead-magnets::resources.delivery_file'),
        };
    }

    /**
     * The "Target" row: the product an access lead magnet grants (one registry
     * lookup, the slug as text when it finds nothing), or the names of the
     * files. A link, or a source that yields nothing, gives no row.
     *
     * @return list<array{label: string, value: string, href: ?string}>
     */
    protected function targetRows(Resource $resource): array
    {
        $label = __('lead-magnets::resources.map_detail_target');

        if ($resource->isAccess()) {
            $slug = trim((string) $resource->access_product);

            if ($slug === '') {
                return [];
            }

            // One lookup, no edges of any addon: the course behind the product
            // is already a connection in the panel.
            $product = app(MapObjectRegistry::class)->lookup('product', $slug)->object;

            return [[
                'label' => $label,
                'value' => Str::limit($product !== null ? $product->label : $slug, 120),
                'href' => $product?->cpUrl,
            ]];
        }

        $names = array_values(array_filter(array_map(
            fn (array $file) => $file['label'] ?? (basename($file['path']) ?: null),
            $resource->fileList(),
        )));

        return $names === [] ? [] : [['label' => $label, 'value' => Str::limit(implode(', ', $names), 120), 'href' => null]];
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
        // Asked from inside coursesByProduct(): the registry only wants the
        // courses addon's edges there, so this provider adds nothing and runs
        // none of its own queries a second time.
        if (static::$collectingCourses) {
            return;
        }

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

        yield from $this->unlockEdges();
    }

    /**
     * The course an access lead magnet opens, one edge per course.
     *
     * The field `access_product` names the product slug the confirmed reader is
     * granted; only a resource of type access with that field set yields an
     * edge. Which course a product opens is the courses addon's knowledge (its
     * edge product -> unlocks -> course), asked through the map registry so this
     * addon needs no course class. A product no course opens, or an installation
     * without courses, yields no edge: an access slug that is no course (a
     * membership) is not an error.
     *
     * @return iterable<int, MapEdge>
     */
    protected function unlockEdges(): iterable
    {
        $resources = Resource::query()
            ->where('delivery_type', Resource::TYPE_ACCESS)
            ->whereNotNull('access_product')
            ->orderBy('id')
            ->get()
            ->filter(fn (Resource $resource) => trim((string) $resource->access_product) !== '');

        if ($resources->isEmpty()) {
            return;
        }

        $coursesByProduct = $this->coursesByProduct();

        foreach ($resources as $resource) {
            $product = trim((string) $resource->access_product);

            // A product no course opens (a membership, a bundle) is legitimate:
            // no edge and no alarm.
            foreach ($coursesByProduct[$product] ?? [] as $course) {
                yield new MapEdge(
                    fromType: self::TYPE,
                    fromId: $this->idOf($resource),
                    toType: 'course',
                    toId: $course,
                    kind: 'unlocks',
                    label: __('lead-magnets::resources.map_edge_unlocks'),
                    derivedFrom: 'field `access_product` of the lead magnet',
                );
            }
        }
    }

    /**
     * Course slugs per product slug, from the edges other providers already derive.
     *
     * Called once per edges() run, and only when an access resource exists. The
     * registry has no narrower accessor than edges() for all providers, so it
     * calls this provider's edges() too; the flag makes that inner call return
     * at once (no loop, no repeated queries).
     *
     * @return array<string, array<int, string>>
     */
    protected function coursesByProduct(): array
    {
        static::$collectingCourses = true;

        try {
            $map = [];

            foreach (app(MapObjectRegistry::class)->edges() as $edge) {
                if ($edge->fromType === 'product' && $edge->toType === 'course' && $edge->kind === 'unlocks') {
                    $map[$edge->fromId][] = $edge->toId;
                }
            }

            return $map;
        } finally {
            static::$collectingCourses = false;
        }
    }

    /**
     * The pairs this addon can draw by hand: an access lead magnet unlocks a course.
     *
     * @return list<array{from_type:string,to_type:string,kind:string,label:string}>
     */
    public function connectionRules(): array
    {
        return [[
            'from_type' => self::TYPE,
            'to_type' => 'course',
            'kind' => 'unlocks',
            'label' => __('lead-magnets::resources.map_connect_unlocks'),
        ]];
    }

    /**
     * Both ends: the permission that guards editing a lead magnet, and the one
     * that lets the user edit the course entry. The latter is Statamic's own
     * `edit {collection} entries` for the courses collection, named by the
     * same config key the courses addon reads (`courses.collections.courses`).
     */
    public function connectionAuthorized(array $c, $user): bool
    {
        if ($user === null) {
            return false;
        }

        $collection = (string) config('courses.collections.courses', 'courses');

        return (bool) $user->can('manage lead magnets') && (bool) $user->can('edit '.$collection.' entries');
    }

    /** @return array{consequence:string,before:string,after:string} */
    public function connectionPreview(array $c, string $action): array
    {
        [$resource, $product, $names] = $this->connectionTarget($c, $action);

        $empty = __('lead-magnets::resources.map_connect_empty');

        return $action === 'connect'
            ? [
                'consequence' => __('lead-magnets::resources.map_connect_consequence', $names),
                'before' => $empty,
                'after' => $product,
            ]
            : [
                'consequence' => __('lead-magnets::resources.map_disconnect_consequence', $names),
                'before' => $product,
                'after' => $empty,
            ];
    }

    /** @return array{before:string,after:string} */
    public function connect(array $c): array
    {
        [$resource, $product] = $this->connectionTarget($c, 'connect');

        // Compare and set: only an empty field is written, and the check is
        // part of the UPDATE, so a change since connectionTarget() read it wins.
        $this->writeIfStill($resource, "TRIM(COALESCE(access_product, '')) = ''", [], $product);

        return ['before' => __('lead-magnets::resources.map_connect_empty'), 'after' => $product];
    }

    /** @return array{before:string,after:string} */
    public function disconnect(array $c): array
    {
        [$resource, $product] = $this->connectionTarget($c, 'disconnect');

        $this->writeIfStill($resource, 'TRIM(access_product) = ?', [$product], null);

        return ['before' => $product, 'after' => __('lead-magnets::resources.map_connect_empty')];
    }

    /**
     * Writes `access_product` only while it still holds the value the check saw.
     *
     * @param  array<int, string>  $bindings
     *
     * @throws \DomainException when the field changed in the meantime
     */
    protected function writeIfStill(Resource $resource, string $condition, array $bindings, ?string $value): void
    {
        $changed = Resource::query()
            ->whereKey($resource->getKey())
            ->whereRaw($condition, $bindings)
            ->update(['access_product' => $value, 'updated_at' => Carbon::now()]);

        if ($changed !== 1) {
            throw new \DomainException(__('lead-magnets::resources.map_connect_changed'));
        }
    }

    /**
     * Checks that the connection can be written now and returns what it needs.
     *
     * @param  array<string, string>  $c
     * @return array{0: resource, 1: string, 2: array{magnet: string, course: string}}
     *
     * @throws \DomainException with a translated reason
     */
    protected function connectionTarget(array $c, string $action): array
    {
        if (! in_array($action, ['connect', 'disconnect'], true)) {
            throw new \DomainException(__('lead-magnets::resources.map_connect_unknown_action'));
        }

        if (($c['from_type'] ?? null) !== self::TYPE || ($c['to_type'] ?? null) !== 'course' || ($c['kind'] ?? 'unlocks') !== 'unlocks') {
            throw new \DomainException(__('lead-magnets::resources.map_connect_unknown_pair'));
        }

        $resource = $this->resolve((string) ($c['from_id'] ?? ''));

        if ($resource === null) {
            throw new \DomainException(__('lead-magnets::resources.map_connect_magnet_missing'));
        }

        $registry = app(MapObjectRegistry::class);
        $courseId = (string) ($c['to_id'] ?? '');
        $course = $registry->lookup('course', $courseId)->object;

        if ($course === null) {
            throw new \DomainException(__('lead-magnets::resources.map_connect_course_missing'));
        }

        $magnetName = $resource->title !== '' ? (string) $resource->title : $this->idOf($resource);
        $names = ['magnet' => $magnetName, 'course' => $course->label];

        if (! $resource->isAccess()) {
            throw new \DomainException(__('lead-magnets::resources.map_connect_not_access', $names));
        }

        $product = $this->productOfCourse($courseId);

        if ($product === null) {
            throw new \DomainException(__('lead-magnets::resources.map_connect_no_product', $names));
        }

        $current = trim((string) $resource->access_product);

        if ($action === 'connect') {
            if ($current === $product) {
                throw new \DomainException(__('lead-magnets::resources.map_connect_already', $names));
            }

            if ($current !== '') {
                throw new \DomainException(__('lead-magnets::resources.map_connect_occupied', $names + ['product' => $current]));
            }
        } elseif ($current !== $product) {
            throw new \DomainException(__('lead-magnets::resources.map_connect_not_connected', $names));
        }

        return [$resource, $product, $names];
    }

    /** The product slug the courses addon says opens this course, or null. */
    protected function productOfCourse(string $courseId): ?string
    {
        foreach ($this->coursesByProduct() as $product => $courses) {
            if (in_array($courseId, $courses, true)) {
                return (string) $product;
            }
        }

        return null;
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
            detail: $this->deliveryLabel($resource),
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
