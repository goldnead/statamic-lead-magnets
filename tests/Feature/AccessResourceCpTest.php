<?php

use Goldnead\LeadMagnets\Integrations\AutomationsBridge;
use Goldnead\LeadMagnets\LeadMagnetsManager;
use Goldnead\LeadMagnets\Models\Resource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\User;

interface FakeSeriesRepoContract {}

class FakeSeriesRepo implements FakeSeriesRepoContract
{
    /** @var list<object> */
    public static array $all = [];

    public function all(): array
    {
        return self::$all;
    }
}

class FakeSeriesRun
{
    /** @var list<string> */
    public static array $ran = [];

    public static bool $throws = false;

    /** @var array<string, string> */
    public array $wheres = [];

    public static function query(): self
    {
        return new self;
    }

    public function where(string $column, mixed $value): self
    {
        $this->wheres[$column] = (string) $value;

        return $this;
    }

    public function exists(): bool
    {
        if (self::$throws) {
            throw new RuntimeException('no history');
        }

        return in_array($this->wheres['automation_uuid'].'|'.$this->wheres['subject_key'], self::$ran, true);
    }
}

class FakeSeriesBridge extends AutomationsBridge
{
    protected function repository(): string
    {
        return FakeSeriesRepoContract::class;
    }

    protected function runModel(): string
    {
        return FakeSeriesRun::class;
    }

    protected function find(string $handle): ?array
    {
        return parent::find($handle);
    }
}

describe('Control Panel', function (): void {
    beforeEach(function (): void {
        Gate::before(fn () => true);

        $manager = User::make()->email('cp-access@example.com');
        $manager->save();

        $this->actingAs($manager);
    });

    it('creates an access resource with a product, a landing path and a series', function (): void {
        $this->post(cp_route('lead-magnets.resources.store'), [
            'title' => 'Minikurs',
            'delivery_type' => 'access',
            'access_product' => 'minikurs-kurs',
            'access_path' => '/account/courses/minikurs',
            'delivery_series' => 'minikurs-serie',
        ])->assertRedirect();

        $resource = Resource::query()->sole();

        expect($resource->isAccess())->toBeTrue()
            ->and($resource->access_product)->toBe('minikurs-kurs')
            ->and($resource->access_path)->toBe('/account/courses/minikurs')
            ->and($resource->seriesHandle())->toBe('minikurs-serie')
            ->and($resource->file_path)->toBeNull()
            ->and($resource->fileList())->toBe([]);
    });

    it('requires a product for an access resource', function (): void {
        $this->post(cp_route('lead-magnets.resources.store'), [
            'title' => 'Minikurs',
            'delivery_type' => 'access',
        ])->assertSessionHasErrors('access_product');

        expect(Resource::query()->count())->toBe(0);
    });

    it('refuses a landing path that leaves the site', function (string $path): void {
        $this->post(cp_route('lead-magnets.resources.store'), [
            'title' => 'Minikurs',
            'delivery_type' => 'access',
            'access_product' => 'minikurs-kurs',
            'access_path' => $path,
        ])->assertSessionHasErrors('access_path');
    })->with(['//evil.example', 'https://evil.example', '/a b', '/a\\b']);

    it('drops the product when the type changes to a file', function (): void {
        $resource = makeResource(['delivery_type' => 'access', 'file_path' => null, 'access_product' => 'p', 'access_path' => '/x']);

        $this->patch(cp_route('lead-magnets.resources.update', $resource->id), [
            'title' => 'Minikurs',
            'delivery_type' => 'link',
            'link_url' => 'https://example.com',
        ])->assertRedirect();

        expect($resource->refresh()->access_product)->toBeNull()
            ->and($resource->access_path)->toBeNull();
    });

    it('shows the access fields on the detail page', function (): void {
        $resource = makeResource(['delivery_type' => 'access', 'file_path' => null, 'access_product' => 'p', 'delivery_series' => 's']);

        $this->get(cp_route('lead-magnets.resources.show', $resource->id))
            ->assertInertia(fn ($page) => $page
                ->where('resource.delivery_type', 'access')
                ->where('resource.access_product', 'p')
                ->where('resource.delivery_series', 's'));
    });
});

describe('Request notice', function (): void {
    it('posts to the webhook once per request, with the configured wording', function (): void {
        Http::fake();
        config([
            'lead-magnets.notifications.webhook_url' => 'https://hooks.example/abc',
            'lead-magnets.notifications.message' => 'Neu: :handle / :email',
        ]);

        makeResource();
        app(LeadMagnetsManager::class)->request(Resource::query()->firstOrFail(), 'reader@example.com');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example/abc'
            && $request['content'] === 'Neu: warm_up / reader@example.com');
    });

    it('does nothing without a webhook url', function (): void {
        Http::fake();

        makeResource();
        app(LeadMagnetsManager::class)->request(Resource::query()->firstOrFail(), 'reader@example.com');

        Http::assertNothingSent();
    });

    it('never stops a request when the endpoint is down', function (): void {
        Http::fake(fn () => throw new RuntimeException('down'));
        config(['lead-magnets.notifications.webhook_url' => 'https://hooks.example/abc']);

        makeResource();
        $grant = app(LeadMagnetsManager::class)->request(Resource::query()->firstOrFail(), 'reader@example.com');

        expect($grant->isPending())->toBeTrue();
    });
});

describe('Automations bridge', function (): void {
    it('says no when the automations addon is not installed', function (): void {
        $bridge = new AutomationsBridge;

        expect($bridge->available())->toBeFalse()
            ->and($bridge->isLive('serie'))->toBeNull()
            ->and($bridge->takesOver('serie', 'a@example.com'))->toBeFalse();
    });

    it('takes over only for a live series the address has not run', function (): void {
        app()->bind(FakeSeriesRepoContract::class, FakeSeriesRepo::class);
        FakeSeriesRepo::$all = [
            (object) ['handle' => 'live', 'uuid' => 'u-live', 'enabled' => true],
            (object) ['handle' => 'off', 'uuid' => 'u-off', 'enabled' => false],
        ];
        FakeSeriesRun::$ran = ['u-live|had@example.com'];

        $bridge = new FakeSeriesBridge;

        expect($bridge->available())->toBeTrue()
            ->and($bridge->takesOver('live', ' New@Example.com '))->toBeTrue()
            ->and($bridge->takesOver('live', 'had@example.com'))->toBeFalse()
            ->and($bridge->takesOver('off', 'new@example.com'))->toBeFalse()
            ->and($bridge->takesOver('missing', 'new@example.com'))->toBeFalse()
            ->and($bridge->takesOver('', 'new@example.com'))->toBeFalse()
            ->and($bridge->isLive('live'))->toBeTrue()
            ->and($bridge->isLive('off'))->toBeFalse()
            ->and($bridge->isLive('missing'))->toBeNull();
    });

    it('falls back to the delivery mail when the history cannot be read', function (): void {
        app()->bind(FakeSeriesRepoContract::class, FakeSeriesRepo::class);
        FakeSeriesRepo::$all = [(object) ['handle' => 'live', 'uuid' => 'u-live', 'enabled' => true]];
        FakeSeriesRun::$throws = true;

        expect((new FakeSeriesBridge)->takesOver('live', 'new@example.com'))->toBeFalse();

        FakeSeriesRun::$throws = false;
    });
});
