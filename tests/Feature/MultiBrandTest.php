<?php

use Goldnead\BrandContext\Models\Brand;
use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Services\DownloadLink;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

/*
 * Multi-brand, which the README promises and which is the one part of this
 * addon nothing else in the suite exercises: the default run is single-brand,
 * where every scope is a no-op and every derivation passes straight through.
 *
 * The three public routes carry no session, so no brand is current and the
 * fail-closed scope would hide the very record the request points at. Each
 * route therefore derives the brand from the value the visitor already holds.
 */

beforeEach(function () {
    config()->set('brand-context.multi_brand', true);

    $this->brandA = Brand::query()->firstOrCreate(['handle' => 'alpha'], ['name' => 'Alpha']);
    $this->brandB = Brand::query()->firstOrCreate(['handle' => 'beta'], ['name' => 'Beta']);
});

afterEach(function () {
    app('brand-context')->forget();
});

function inBrand(Brand $brand, Closure $callback): mixed
{
    return app('brand-context')->runFor($brand, $callback);
}

it('scopes resources and grants to the brand that owns them', function () {
    $alpha = inBrand($this->brandA, fn () => makeResource(['handle' => 'alpha_thing']));
    $beta = inBrand($this->brandB, fn () => makeResource(['handle' => 'beta_thing']));

    expect($alpha->brand_id)->toBe($this->brandA->id)
        ->and($beta->brand_id)->toBe($this->brandB->id);

    inBrand($this->brandA, function () {
        expect(Resource::query()->pluck('handle')->all())->toBe(['alpha_thing']);
    });

    inBrand($this->brandB, function () {
        expect(Resource::query()->pluck('handle')->all())->toBe(['beta_thing']);
    });
});

it('derives the brand from the resource handle on the session-less request route', function () {
    inBrand($this->brandB, fn () => makeResource(['handle' => 'beta_thing']));

    // No brand is current here — exactly the state a visitor's browser
    // arrives in. Without the derivation the fail-closed scope hides the
    // resource and the form 404s.
    app('brand-context')->forget();

    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'beta_thing',
    ])->assertRedirect();

    $grant = Grant::query()->withoutGlobalScopes()->sole();

    expect($grant->brand_id)->toBe($this->brandB->id);
});

it('derives the brand from the confirmation token', function () {
    inBrand($this->brandB, fn () => makeResource(['handle' => 'beta_thing']));

    app('brand-context')->forget();

    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'beta_thing',
    ]);

    $token = tokenFromLastConfirmationMail();

    app('brand-context')->forget();

    $this->get(route('lead-magnets.confirm', ['token' => $token]))->assertOk();

    expect(Grant::query()->withoutGlobalScopes()->with('entitlement')->sole()->state())->toBe(EntitlementState::Active);
});

it('derives the brand from the grant on the download route', function () {
    inBrand($this->brandB, fn () => makeResource([
        'handle' => 'beta_thing',
        'requires_confirmation' => false,
    ]));

    app('brand-context')->forget();

    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'beta_thing',
    ]);

    $grant = Grant::query()->withoutGlobalScopes()->with('resource')->sole();

    $url = inBrand($this->brandB, fn () => app(DownloadLink::class)->for($grant));

    app('brand-context')->forget();

    $this->get($url)->assertOk()->assertDownload();

    expect(Grant::query()->withoutGlobalScopes()->sole()->download_count)->toBe(1);
});

it('will not let two brands own the same resource handle', function () {
    inBrand($this->brandA, fn () => makeResource(['handle' => 'shared']));

    // Globally unique, not unique per brand — and that is the whole reason the
    // request route above can derive a brand from a handle at all. See the
    // migration for the trade this makes.
    expect(fn () => inBrand($this->brandB, fn () => makeResource(['handle' => 'shared'])))
        ->toThrow(QueryException::class);
});

it('carries the configured throttle on the request endpoint', function () {
    // The README promises a throttle, and a promise with no test behind it is
    // a claim. The rest of the suite raises the limit so it cannot turn an
    // unrelated eleventh request into a 429; the limit is read once, when the
    // route is registered, so what is checkable here is that the middleware is
    // on the route and carries the configured value.
    $middleware = collect(Route::getRoutes())
        ->first(fn ($route) => $route->getName() === 'lead-magnets.request')
        ->gatherMiddleware();

    // Against the shipped default, not the runtime value: the suite relaxes
    // the runtime one in setUp(), long after the route was registered, so a
    // check against `config()` here would only assert that two lines of the
    // test harness agree with each other.
    $shipped = (require __DIR__.'/../../config/lead-magnets.php')['requests']['throttle'];

    expect($middleware)->toContain('throttle:'.$shipped);
});
