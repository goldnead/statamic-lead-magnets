<?php

use Goldnead\BrandContext\Contracts\ProvidesMapEdges;
use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapEdge;
use Goldnead\BrandContext\Map\MapLookup;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\LeadMagnets\Integrations\Map\LeadMagnetMapObjects;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Services\GrantService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\User;

/*
 * The lead magnets as map objects for statamic-funnels.
 *
 * The clock is fixed at HEUTE. Requests are made through the real GrantService
 * with the clock moved to the moment they belong to, the same way the insights
 * tests build their fixture, so the stored column is the one under test.
 */

const MAP_HEUTE = '2026-08-20 12:00:00';

function mapResource(string $handle, string $title, bool $published = true, string $type = Resource::TYPE_LINK): Resource
{
    return Resource::query()->create([
        'handle' => $handle,
        'title' => $title,
        'delivery_type' => $type,
        'link_url' => 'https://example.test/'.$handle,
        'requires_confirmation' => true,
        'published' => $published,
    ]);
}

function mapRequestAt(string $moment, Resource $resource, string $email): void
{
    Carbon::setTestNow(Carbon::parse($moment));

    try {
        app(GrantService::class)->request($resource, $email);
    } finally {
        Carbon::setTestNow(Carbon::parse(MAP_HEUTE));
    }
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse(MAP_HEUTE));

    $this->map = app(LeadMagnetMapObjects::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('reports its type key and a translated type label', function () {
    expect($this->map->type())->toBe('lead_magnet')
        ->and($this->map->typeLabel())->toBe('Lead Magnet');
});

it('lists the resources sorted by title and limits them', function () {
    mapResource('warm_up', 'Warm-up routine');
    mapResource('atmung', 'Atemübungen');
    mapResource('stimme_100', '100% Stimme');
    mapResource('stimme_ohne', 'Stimme ohne Prozent');

    $titles = array_map(fn ($object) => $object->label, $this->map->objects());

    expect($titles)->toBe(['100% Stimme', 'Atemübungen', 'Stimme ohne Prozent', 'Warm-up routine'])
        ->and($this->map->objects(null, 2))->toHaveCount(2);
});

it('filters by title or handle and treats LIKE wildcards as plain characters', function () {
    mapResource('warm_up', 'Warm-up routine');
    mapResource('atmung', 'Atemübungen');
    mapResource('stimme_100', '100% Stimme');
    mapResource('stimme_ohne', 'Stimme ohne Prozent');

    $labels = fn (?string $query) => array_map(fn ($object) => $object->label, $this->map->objects($query));

    expect($labels('stimme'))->toBe(['100% Stimme', 'Stimme ohne Prozent'])
        ->and($labels('atemüb'))->toBe(['Atemübungen'])
        ->and($labels('warm_up'))->toBe(['Warm-up routine'])
        // A bare `%` matches only the title that contains one, not every row.
        ->and($labels('%'))->toBe(['100% Stimme'])
        // A bare `_` matches the handles that contain one, not every row.
        ->and($labels('_'))->toBe(['100% Stimme', 'Stimme ohne Prozent', 'Warm-up routine'])
        ->and($labels('gibt es nicht'))->toBe([])
        ->and($labels('   '))->toHaveCount(4);
});

it('finds one object by handle and says so when it is gone', function () {
    mapResource('atmung', 'Atemübungen');

    $object = $this->map->find('atmung');

    expect($object)->not->toBeNull()
        ->and($object->type)->toBe('lead_magnet')
        ->and($object->id)->toBe('atmung')
        ->and($object->label)->toBe('Atemübungen')
        ->and($object->cpUrl)->toEndWith('/cp/lead-magnets/resources/'.Resource::query()->where('handle', 'atmung')->value('id'))
        ->and($object->detail)->toBe('Link')
        ->and($this->map->find('gibt-es-nicht'))->toBeNull();
});

it('maps the published switch to enabled and leaves a resource without one as on or off', function () {
    mapResource('an', 'Veröffentlicht', published: true);
    mapResource('aus', 'Entwurf', published: false);

    expect($this->map->find('an')->enabled)->toBeTrue()
        ->and($this->map->find('aus')->enabled)->toBeFalse();
});

it('counts requests for one resource over the period only', function () {
    $warmUp = mapResource('warm_up', 'Warm-up routine');
    $atmung = mapResource('atmung', 'Atemübungen');

    mapRequestAt('2026-08-12 09:00:00', $warmUp, 'anna@example.com');
    mapRequestAt('2026-08-13 09:00:00', $warmUp, 'bruno@example.com');
    mapRequestAt('2026-08-14 09:00:00', $atmung, 'anna@example.com');
    // Six weeks before the period: in no figure of it.
    mapRequestAt('2026-07-02 09:00:00', $warmUp, 'dora@example.com');
    // After now, so not yet counted: the figure never looks into the future.
    mapRequestAt('2026-08-21 09:00:00', $warmUp, 'erik@example.com');

    $period = MapPeriod::lastDays(30);

    $metric = $this->map->metric('warm_up', $period);

    expect($metric)->not->toBeNull()
        ->and($metric->value)->toBe(2)
        ->and($metric->what)->toBe('Requests')
        ->and($metric->period)->toBe($period)
        ->and($this->map->metric('atmung', $period)->value)->toBe(1);
});

it('returns no metric for a resource that does not exist', function () {
    expect($this->map->metric('gibt-es-nicht', MapPeriod::lastDays(30)))->toBeNull();
});

/** Stands in for the courses addon: knows the product -> course edge and the course's title. */
class DetailsFakeCourseMapObjects implements ProvidesMapEdges, ProvidesMapObjects
{
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
        return $id === 'kurs-vokale'
            ? new MapObject('course', $id, 'Vokale Minikurs', '/cp/kurs', true)
            : null;
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }

    public function edges(): iterable
    {
        yield new MapEdge('product', 'vokale-produkt', 'course', 'kurs-vokale', 'unlocks', 'unlocks', 'course field `product`');
    }
}

/** @return array<string, string> label => value of the first facts block */
function detailRows(?array $blocks): array
{
    $rows = [];

    foreach (($blocks[0]['rows'] ?? []) as $row) {
        $rows[$row['label']] = $row['value'];
    }

    return $rows;
}

it('details: facts with delivery type, requests in the period and the file as target', function () {
    $resource = mapResource('atmung', 'Atemübungen', true, Resource::TYPE_FILE);
    $resource->update(['link_url' => null, 'files' => [['key' => 'a', 'path' => 'pdf/atmung-uebung.pdf', 'label' => null, 'group' => null]]]);

    mapRequestAt('2026-08-10 09:00:00', $resource, 'a@example.test');
    mapRequestAt('2026-08-12 09:00:00', $resource, 'b@example.test');
    mapRequestAt('2026-06-01 09:00:00', $resource, 'c@example.test');

    $period = MapPeriod::lastDays(30);
    $blocks = $this->map->details('atmung', $period);

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['kind'])->toBe('facts')
        ->and(detailRows($blocks))->toBe([
            'Delivery' => 'File',
            'Requests' => '2',
            'Target' => 'atmung-uebung.pdf',
        ])
        // The very same figure the card shows.
        ->and((string) $this->map->metric('atmung', $period)->value)->toBe('2');
});

/** Stands in for the products addon: finds a product by slug and counts how often it is asked. */
class DetailsFakeProductMapObjects implements ProvidesMapEdges, ProvidesMapObjects
{
    public static int $edgeCalls = 0;

    public static int $findCalls = 0;

    public function type(): string
    {
        return 'product';
    }

    public function typeLabel(): string
    {
        return 'Product';
    }

    public function objects(?string $query = null, int $limit = 50): array
    {
        return [];
    }

    public function find(string $id): ?MapObject
    {
        self::$findCalls++;

        return $id === 'vokale-produkt' ? new MapObject('product', $id, 'Vokale Produkt', '/cp/produkt', true) : null;
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }

    public function edges(): iterable
    {
        self::$edgeCalls++;

        return [];
    }
}

it('details: an access lead magnet names the product it grants, with a link, from one lookup and no edges', function () {
    DetailsFakeProductMapObjects::$edgeCalls = 0;
    DetailsFakeProductMapObjects::$findCalls = 0;
    app(MapObjectRegistry::class)->register(DetailsFakeProductMapObjects::class);
    app(MapObjectRegistry::class)->register(DetailsFakeCourseMapObjects::class);
    mapResource('minikurs', 'Minikurs', true, Resource::TYPE_ACCESS)->update(['link_url' => null, 'access_product' => 'vokale-produkt']);

    $blocks = $this->map->details('minikurs', MapPeriod::lastDays(30));

    expect(detailRows($blocks))->toBe(['Delivery' => 'Access', 'Requests' => '0', 'Target' => 'Vokale Produkt'])
        ->and($blocks[0]['rows'][2]['href'])->toBe('/cp/produkt')
        ->and(DetailsFakeProductMapObjects::$findCalls)->toBe(1)
        ->and(DetailsFakeProductMapObjects::$edgeCalls)->toBe(0);
});

it('details: an access product the registry does not know is shown as its slug', function () {
    app(MapObjectRegistry::class)->register(DetailsFakeProductMapObjects::class);
    mapResource('mitglied', 'Mitglied', true, Resource::TYPE_ACCESS)->update(['link_url' => null, 'access_product' => 'mitgliedschaft']);

    $blocks = $this->map->details('mitglied', MapPeriod::lastDays(30));

    expect(detailRows($blocks)['Target'])->toBe('mitgliedschaft')
        ->and($blocks[0]['rows'][2]['href'])->toBeNull();
});

it('details: falls back to the slug without a products provider', function () {
    mapResource('mitglied', 'Mitglied', true, Resource::TYPE_ACCESS)->update(['link_url' => null, 'access_product' => 'mitgliedschaft']);

    expect(detailRows($this->map->details('mitglied', MapPeriod::lastDays(30)))['Target'])->toBe('mitgliedschaft');
});

it('details: omits the target row when there is no source for it', function () {
    // A link has no file; an access lead magnet with no product has nothing to show.
    mapResource('linkmagnet', 'Linkmagnet');
    mapResource('leer', 'Leer', true, Resource::TYPE_ACCESS)->update(['link_url' => null, 'access_product' => null]);

    expect(detailRows($this->map->details('linkmagnet', MapPeriod::lastDays(30))))->toBe(['Delivery' => 'Link', 'Requests' => '0'])
        ->and(detailRows($this->map->details('leer', MapPeriod::lastDays(30))))->toBe(['Delivery' => 'Access', 'Requests' => '0'])
        ->and($this->map->details('gibt-es-nicht', MapPeriod::lastDays(30)))->toBeNull();
});

it('detailsAuthorized: a user who may manage lead magnets is allowed', function () {
    $user = User::make()->email('panel@example.test');
    $user->save();

    Gate::before(fn ($u, $ability) => $ability === 'manage lead magnets');

    expect($this->map->detailsAuthorized('atmung', $user))->toBeTrue();
});

it('detailsAuthorized: fail closed without the permission and without a user', function () {
    $user = User::make()->email('panel3@example.test');
    $user->save();

    Gate::before(fn ($u, $ability) => false);

    expect($this->map->detailsAuthorized('atmung', $user))->toBeFalse()
        ->and($this->map->detailsAuthorized('atmung', null))->toBeFalse();
});

it('detailsAuthorized: the registry asks it and refuses without a permitted user', function () {
    if (! method_exists(MapObjectRegistry::class, 'detailsAllowed')) {
        $this->markTestSkipped('needs statamic-brand-context 1.20 (MapObjectRegistry::detailsAllowed())');
    }

    $user = User::make()->email('panel2@example.test');
    $user->save();
    Gate::before(fn ($u, $ability) => false);

    expect(app(MapObjectRegistry::class)->detailsAllowed('lead_magnet', 'atmung', $user))->toBeFalse();
});

it('is registered in the map registry after boot', function () {
    $registry = app(MapObjectRegistry::class);

    expect($registry->has('lead_magnet'))->toBeTrue()
        ->and($registry->types())->toHaveKey('lead_magnet', 'Lead Magnet');

    mapResource('atmung', 'Atemübungen');

    expect($registry->lookup('lead_magnet', 'atmung')->status)->toBe(MapLookup::FOUND)
        ->and($registry->lookup('lead_magnet', 'gibt-es-nicht')->status)->toBe(MapLookup::MISSING);
});
