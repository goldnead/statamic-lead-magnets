<?php

use Goldnead\BrandContext\Contracts\ProvidesMapEdges;
use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapEdge;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\LeadMagnets\Integrations\Map\LeadMagnetMapObjects;
use Goldnead\LeadMagnets\Models\Resource;
use Illuminate\Support\Facades\DB;

/*
 * A lead magnet with access hands the reader a product slug (`access_product`).
 * The courses addon says which course that slug opens (edge product -> unlocks ->
 * course). The map edge goes from the lead magnet straight to that course, and
 * is computed, never stored. A slug no course opens stays visible as a dead
 * reference.
 */

/** Stands in for the courses addon: one course per product, as its own edges() would say. */
class FakeCourseMapObjects implements ProvidesMapEdges, ProvidesMapObjects
{
    /** @var array<string, array<int, string>> product => course slugs */
    public static array $courses = [];

    public static int $calls = 0;

    public function type(): string
    {
        return 'course';
    }

    public function typeLabel(): string
    {
        return 'Course';
    }

    public function objects(?string $query = null, int $limit = 50): array
    {
        return [];
    }

    public function find(string $id): ?MapObject
    {
        return null;
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }

    public function edges(): iterable
    {
        self::$calls++;

        foreach (self::$courses as $product => $slugs) {
            foreach ($slugs as $slug) {
                yield new MapEdge('product', $product, 'course', $slug, 'unlocks', 'unlocks', 'course field `product`');
            }
        }
    }
}

function accessResource(string $handle, ?string $product, string $type = Resource::TYPE_ACCESS): Resource
{
    return makeResource([
        'handle' => $handle,
        'title' => $handle,
        'delivery_type' => $type,
        'file_path' => null,
        'link_url' => $type === Resource::TYPE_LINK ? 'https://example.test/'.$handle : null,
        'access_product' => $product,
    ]);
}

/** @return array<int, MapEdge> */
function unlockEdges(): array
{
    return array_values(array_filter(
        iterator_to_array(app(LeadMagnetMapObjects::class)->edges(), false),
        fn (MapEdge $edge) => $edge->kind === 'unlocks',
    ));
}

beforeEach(function (): void {
    FakeCourseMapObjects::$courses = [];
    app(MapObjectRegistry::class)->register(FakeCourseMapObjects::class);
});

it('draws an edge from the access lead magnet to the course its product opens', function (): void {
    FakeCourseMapObjects::$courses = ['vokale-produkt' => ['kurs-vokale']];
    accessResource('minikurs', 'vokale-produkt');

    $edges = unlockEdges();

    expect($edges)->toHaveCount(1)
        ->and($edges[0]->fromType)->toBe('lead_magnet')
        ->and($edges[0]->fromId)->toBe('minikurs')
        ->and($edges[0]->toType)->toBe('course')
        ->and($edges[0]->toId)->toBe('kurs-vokale')
        ->and($edges[0]->label)->toBe('unlocks')
        ->and($edges[0]->derivedFrom)->toContain('access_product')
        ->and($edges[0]->toLabel)->toBeNull()
        ->and($edges[0]->dashed)->toBeFalse();
});

it('draws one edge per course when a product opens several', function (): void {
    FakeCourseMapObjects::$courses = ['paket' => ['kurs-a', 'kurs-b']];
    accessResource('minikurs', 'paket');

    expect(array_map(fn (MapEdge $e) => $e->toId, unlockEdges()))->toBe(['kurs-a', 'kurs-b']);
});

it('draws no edge and raises no alarm when no course opens the product', function (): void {
    // A membership, a bundle: an access slug that is not a course is legitimate.
    FakeCourseMapObjects::$courses = ['vokale-produkt' => ['kurs-vokale']];
    accessResource('mitglied', 'mitgliedschaft');

    $all = iterator_to_array(app(LeadMagnetMapObjects::class)->edges(), false);

    expect(unlockEdges())->toBe([])
        ->and(array_filter($all, fn (MapEdge $e) => $e->toLabel !== null))->toBe([]);
});

it('matches on the product slug, not on the course handle (slug differs from handle)', function (): void {
    // course.product is an entitlement slug; the course's own handle is another string.
    FakeCourseMapObjects::$courses = ['vokale-produkt' => ['kurs-vokale']];
    accessResource('richtig', 'vokale-produkt');
    accessResource('falsch', 'kurs-vokale');

    $edges = unlockEdges();

    expect($edges)->toHaveCount(1)
        ->and($edges[0]->fromId)->toBe('richtig')
        ->and($edges[0]->toId)->toBe('kurs-vokale');
});

it('computes the graph once per call and does not re-run its own queries inside', function (): void {
    FakeCourseMapObjects::$courses = ['vokale-produkt' => ['kurs-vokale']];
    accessResource('minikurs', 'vokale-produkt');
    app(MapObjectRegistry::class)->register(LeadMagnetMapObjects::class);
    $provider = app(LeadMagnetMapObjects::class);

    FakeCourseMapObjects::$calls = 0;
    $queries = 0;
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, 'lead_magnet_resources')) {
            $queries++;
        }
    });

    iterator_to_array($provider->edges(), false);

    expect(FakeCourseMapObjects::$calls)->toBe(1)
        ->and($queries)->toBe(2); // page edges + access edges, once
});

it('draws no edge for a link resource, a file resource or an access resource without a target', function (): void {
    FakeCourseMapObjects::$courses = ['vokale-produkt' => ['kurs-vokale']];
    accessResource('verlinkt', 'vokale-produkt', Resource::TYPE_LINK);
    accessResource('leer', null);
    accessResource('blank', '   ');

    expect(unlockEdges())->toBe([]);
});

it('hands the edge to the map registry without looping through itself', function (): void {
    app(MapObjectRegistry::class)->register(LeadMagnetMapObjects::class);
    FakeCourseMapObjects::$courses = ['vokale-produkt' => ['kurs-vokale']];
    accessResource('minikurs', 'vokale-produkt');

    $edges = app(MapObjectRegistry::class)->edges();

    expect($edges)->toHaveKey('lead_magnet:minikurs>unlocks>course:kurs-vokale');
});
