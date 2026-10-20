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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\User;

/*
 * Drawing an arrow from an access lead magnet to a course writes the field
 * `access_product` of the lead magnet with the product slug that course is
 * opened by. The pair is offered by this addon, written through its model, and
 * gated by the permission that guards editing a lead magnet in the Control
 * Panel (`manage lead magnets`).
 */

/** Stands in for the courses addon: course objects plus their product -> course edges. */
class FakeConnectCourseObjects implements ProvidesMapEdges, ProvidesMapObjects
{
    /** @var array<string, array{product: ?string, title: string}> course slug => data */
    public static array $courses = [];

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
        $course = self::$courses[$id] ?? null;

        return $course ? new MapObject('course', $id, $course['title'], '/cp/courses/'.$id, true) : null;
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }

    public function edges(): iterable
    {
        foreach (self::$courses as $slug => $course) {
            if ($course['product'] !== null) {
                yield new MapEdge('product', $course['product'], 'course', $slug, 'unlocks', 'unlocks', 'course field `product`');
            }
        }
    }
}

function connection(string $magnet = 'minikurs', string $course = 'kurs-vokale'): array
{
    return ['from_type' => 'lead_magnet', 'from_id' => $magnet, 'to_type' => 'course', 'to_id' => $course, 'kind' => 'unlocks'];
}

function connectAccessMagnet(string $handle, ?string $product, string $type = Resource::TYPE_ACCESS): Resource
{
    return makeResource([
        'handle' => $handle,
        'title' => 'Mini '.$handle,
        'delivery_type' => $type,
        'file_path' => null,
        'link_url' => $type === Resource::TYPE_LINK ? 'https://example.test/'.$handle : null,
        'access_product' => $product,
    ]);
}

beforeEach(function (): void {
    FakeConnectCourseObjects::$courses = [
        'kurs-vokale' => ['product' => 'vokale-produkt', 'title' => 'Vokale'],
        'kurs-ohne' => ['product' => null, 'title' => 'Ohne Produkt'],
    ];
    app(MapObjectRegistry::class)->register(FakeConnectCourseObjects::class);
    $this->provider = app(LeadMagnetMapObjects::class);
});

it('offers exactly the lead magnet to course unlocks pair', function (): void {
    $rules = $this->provider->connectionRules();

    expect($rules)->toHaveCount(1)
        ->and($rules[0])->toMatchArray(['from_type' => 'lead_magnet', 'to_type' => 'course', 'kind' => 'unlocks'])
        ->and($rules[0]['label'])->toBe(__('lead-magnets::resources.map_connect_unlocks'))
        ->and($rules[0]['label'])->not->toBe('lead-magnets::resources.map_connect_unlocks');
});

it('writes the product slug of the course into access_product and reverses it exactly', function (): void {
    $magnet = connectAccessMagnet('minikurs', null);

    $result = $this->provider->connect(connection());

    expect($magnet->fresh()->access_product)->toBe('vokale-produkt')
        ->and($result['before'])->not->toBe($result['after'])
        ->and($result['after'])->toContain('vokale-produkt');

    $this->provider->disconnect(connection());

    expect($magnet->fresh()->access_product)->toBeNull()
        ->and($magnet->fresh()->delivery_type)->toBe(Resource::TYPE_ACCESS);
});

it('treats a blank access_product as empty', function (): void {
    $magnet = connectAccessMagnet('minikurs', '  ');

    $this->provider->connect(connection());

    expect($magnet->fresh()->access_product)->toBe('vokale-produkt');
});

it('names the lead magnet and the course in the plain-language consequence', function (): void {
    connectAccessMagnet('minikurs', null);

    $connect = $this->provider->connectionPreview(connection(), 'connect');

    expect($connect['consequence'])->toContain('Mini minikurs')->toContain('Vokale')
        ->and($connect)->toHaveKeys(['consequence', 'before', 'after']);

    $this->provider->connect(connection());
    $disconnect = $this->provider->connectionPreview(connection(), 'disconnect');

    expect($disconnect['consequence'])->toContain('Mini minikurs')->toContain('Vokale');
});

it('refuses a lead magnet that is not of type access, writing nothing', function (): void {
    $magnet = connectAccessMagnet('verlinkt', null, Resource::TYPE_LINK);

    expect(fn () => $this->provider->connectionPreview(connection('verlinkt'), 'connect'))
        ->toThrow(DomainException::class);
    expect(fn () => $this->provider->connect(connection('verlinkt')))->toThrow(DomainException::class);
    expect($magnet->fresh()->access_product)->toBeNull();
});

it('refuses a field that already holds another product, and never overwrites it', function (): void {
    $magnet = connectAccessMagnet('minikurs', 'mitgliedschaft');

    expect(fn () => $this->provider->connectionPreview(connection(), 'connect'))
        ->toThrow(DomainException::class, 'mitgliedschaft');
    expect(fn () => $this->provider->connect(connection()))->toThrow(DomainException::class);
    expect($magnet->fresh()->access_product)->toBe('mitgliedschaft');
});

it('refuses to connect what is already connected and to disconnect what is not', function (): void {
    connectAccessMagnet('minikurs', 'vokale-produkt');
    connectAccessMagnet('leer', null);
    connectAccessMagnet('anders', 'mitgliedschaft');

    expect(fn () => $this->provider->connectionPreview(connection(), 'connect'))->toThrow(DomainException::class);
    expect(fn () => $this->provider->connectionPreview(connection('leer'), 'disconnect'))->toThrow(DomainException::class);
    // Another product is not ours to clear.
    expect(fn () => $this->provider->disconnect(connection('anders')))->toThrow(DomainException::class);
    expect(Resource::query()->where('handle', 'anders')->first()->access_product)->toBe('mitgliedschaft');
});

it('refuses a course without a product slug and a missing lead magnet or course', function (): void {
    connectAccessMagnet('minikurs', null);

    expect(fn () => $this->provider->connectionPreview(connection('minikurs', 'kurs-ohne'), 'connect'))
        ->toThrow(DomainException::class);
    expect(fn () => $this->provider->connectionPreview(connection('minikurs', 'gibt-es-nicht'), 'connect'))
        ->toThrow(DomainException::class);
    expect(fn () => $this->provider->connectionPreview(connection('gibt-es-nicht'), 'connect'))
        ->toThrow(DomainException::class);
    expect(Resource::query()->where('handle', 'minikurs')->first()->access_product)->toBeNull();
});

it('refuses an unknown action', function (): void {
    connectAccessMagnet('minikurs', null);

    expect(fn () => $this->provider->connectionPreview(connection(), 'frobnicate'))->toThrow(DomainException::class);
});

it('authorises only users who may manage lead magnets', function (): void {
    $user = User::make()->email('editor@example.test');
    $user->save();

    Gate::before(fn ($u, $ability) => $ability === 'manage lead magnets' ? false : null);
    expect($this->provider->connectionAuthorized(connection(), $user))->toBeFalse()
        ->and($this->provider->connectionAuthorized(connection(), null))->toBeFalse();
});

function userWith(string $email, array $abilities): mixed
{
    $user = User::make()->email($email);
    $user->save();

    Gate::before(fn ($u, $ability) => in_array($ability, $abilities, true) ? true : false);

    return $user;
}

it('authorises a user holding the source and the target permission', function (): void {
    $user = userWith('beide@example.test', ['manage lead magnets', 'edit courses entries']);

    expect($this->provider->connectionAuthorized(connection(), $user))->toBeTrue();
});

it('refuses a user who may only manage lead magnets, not edit the course', function (): void {
    $user = userWith('quelle@example.test', ['manage lead magnets']);

    expect($this->provider->connectionAuthorized(connection(), $user))->toBeFalse();
});

it('refuses a user who may only edit the course, not manage lead magnets', function (): void {
    $user = userWith('ziel@example.test', ['edit courses entries']);

    expect($this->provider->connectionAuthorized(connection(), $user))->toBeFalse();
});

it('derives the target permission from the configured courses collection', function (): void {
    config(['courses.collections.courses' => 'kurse']);
    $user = userWith('kurse@example.test', ['manage lead magnets', 'edit kurse entries']);

    expect($this->provider->connectionAuthorized(connection(), $user))->toBeTrue();
});

it('does not accept the default courses permission when the collection is renamed', function (): void {
    config(['courses.collections.courses' => 'kurse']);
    $user = userWith('kurse2@example.test', ['manage lead magnets', 'edit courses entries']);

    expect($this->provider->connectionAuthorized(connection(), $user))->toBeFalse();
});

/** A provider whose field changes after the preview checked it and before the write. */
class RacingLeadMagnetMapObjects extends LeadMagnetMapObjects
{
    public static ?string $changeTo = null;

    protected function connectionTarget(array $c, string $action): array
    {
        $target = parent::connectionTarget($c, $action);

        Resource::query()->where('handle', $c['from_id'])->update(['access_product' => self::$changeTo]);

        return $target;
    }
}

it('writes nothing on connect when the field changed after the preview', function (): void {
    $magnet = connectAccessMagnet('minikurs', null);
    RacingLeadMagnetMapObjects::$changeTo = 'mitgliedschaft';

    expect(fn () => app(RacingLeadMagnetMapObjects::class)->connect(connection()))
        ->toThrow(DomainException::class, __('lead-magnets::resources.map_connect_changed'));
    expect($magnet->fresh()->access_product)->toBe('mitgliedschaft');
});

it('writes nothing on disconnect when the field changed after the preview', function (): void {
    $magnet = connectAccessMagnet('minikurs', 'vokale-produkt');
    RacingLeadMagnetMapObjects::$changeTo = 'mitgliedschaft';

    expect(fn () => app(RacingLeadMagnetMapObjects::class)->disconnect(connection()))
        ->toThrow(DomainException::class, __('lead-magnets::resources.map_connect_changed'));
    expect($magnet->fresh()->access_product)->toBe('mitgliedschaft');
});

it('has the change message in German and English', function (): void {
    expect(__('lead-magnets::resources.map_connect_changed'))->not->toBe('lead-magnets::resources.map_connect_changed');
    app()->setLocale('de');
    expect(__('lead-magnets::resources.map_connect_changed'))->not->toBe('lead-magnets::resources.map_connect_changed');
});

describe('through the registry', function (): void {
    beforeEach(function (): void {
        if (! method_exists(MapObjectRegistry::class, 'connectionRules')) {
            $this->markTestSkipped('Installed brand-context predates connection rules.');
        }

        app(MapObjectRegistry::class)->register(LeadMagnetMapObjects::class);
    });

    it('owns the lead magnet to course unlocks rule and edge', function (): void {
        connectAccessMagnet('minikurs', 'vokale-produkt');
        $registry = app(MapObjectRegistry::class);

        $rule = $registry->ruleFor('lead_magnet', 'course');
        $edge = collect($registry->edges())->first(fn ($e) => $e->fromType === 'lead_magnet' && $e->kind === 'unlocks');

        expect($rule['owner'])->toBe('lead_magnet')
            ->and($edge)->not->toBeNull()
            ->and($edge->ownerType)->toBe('lead_magnet')
            ->and($edge->toId)->toBe('kurs-vokale');
    });

    it('connects and disconnects end to end for a user with both permissions', function (): void {
        $magnet = connectAccessMagnet('minikurs', null);
        $user = userWith('e2e@example.test', ['manage lead magnets', 'edit courses entries']);
        $registry = app(MapObjectRegistry::class);

        $preview = $registry->previewConnection(connection(), 'connect', $user);
        $made = $registry->applyConnection(connection(), 'connect', $user, $preview['before']);

        expect($magnet->fresh()->access_product)->toBe('vokale-produkt');

        $registry->applyConnection(connection(), 'disconnect', $user);

        expect($magnet->fresh()->access_product)->toBeNull()
            ->and($made['undo']['action'])->toBe('disconnect');
    });

    it('refuses preview and write for a user without the target permission', function (): void {
        $magnet = connectAccessMagnet('minikurs', null);
        $user = userWith('nur-quelle@example.test', ['manage lead magnets']);
        $registry = app(MapObjectRegistry::class);

        expect(fn () => $registry->previewConnection(connection(), 'connect', $user))
            ->toThrow(AuthorizationException::class);
        expect(fn () => $registry->applyConnection(connection(), 'connect', $user))
            ->toThrow(AuthorizationException::class);
        expect($magnet->fresh()->access_product)->toBeNull();
    });
});
