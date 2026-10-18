<?php

use Goldnead\BrandContext\Map\MapLookup;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\LeadMagnets\Integrations\Map\LeadMagnetMapObjects;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Services\GrantService;
use Illuminate\Support\Carbon;

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

it('is registered in the map registry after boot', function () {
    $registry = app(MapObjectRegistry::class);

    expect($registry->has('lead_magnet'))->toBeTrue()
        ->and($registry->types())->toHaveKey('lead_magnet', 'Lead Magnet');

    mapResource('atmung', 'Atemübungen');

    expect($registry->lookup('lead_magnet', 'atmung')->status)->toBe(MapLookup::FOUND)
        ->and($registry->lookup('lead_magnet', 'gibt-es-nicht')->status)->toBe(MapLookup::MISSING);
});
