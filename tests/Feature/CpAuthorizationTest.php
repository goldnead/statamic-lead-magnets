<?php

/**
 * Every Control Panel route refuses a user who holds no lead-magnets permission.
 *
 * The route list is walked rather than typed out. A hand-written list only
 * catches a missing guard if somebody remembers to extend it, and the studio's
 * reference analysis found exactly that gap in one of the largest third-party
 * addons in the set — store, update and destroy left open to any authenticated
 * CP user. A new unguarded route fails here on the day it is added.
 *
 * Two things about how the refusals are asserted.
 *
 * The users below hold `access cp` and nothing else. Without it Statamic's own
 * middleware turns every request away before this addon's guard is consulted,
 * and the result would read as "refused" while proving nothing about the addon.
 *
 * And every refusal is requested with `Accept: application/json`. Statamic's CP
 * exception renderer turns an `AuthorizationException` — which is what the
 * route's `can:` middleware throws — into a 302 back to the Control Panel for
 * a browser request, and into a 403 for a request that expects JSON. A sweep
 * that accepted the 302 would also accept a redirect thrown for some entirely
 * different reason, so it asks for the status code that can only mean one
 * thing.
 */

use Goldnead\LeadMagnets\Models\Grant;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\User;

/**
 * Sign in as a CP user holding exactly the listed permissions.
 *
 * Granted through the gate rather than through a role fixture on purpose: the
 * subject here is this addon's controller check, and a role file would test
 * Statamic's permission loading instead.
 *
 * @param  list<string>  $permissions
 */
function actingAsCpUser(string $email, array $permissions = []): void
{
    $allowed = array_merge(['access cp'], $permissions);

    Gate::before(fn ($user, $ability) => in_array($ability, $allowed, true) ? true : null);

    $account = User::make()->email($email);
    $account->save();

    test()->actingAs($account);
}

/**
 * @param  list<string>  $methods
 * @return array<int, array{name: string, method: string, uri: string}>
 */
function leadMagnetRoutes(array $methods): array
{
    $found = [];

    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();

        // Statamic prefixes CP route names with `statamic.cp.`, so this group's
        // own `lead-magnets.` prefix sits in the middle of the registered name.
        if (! $name || ! str_contains($name, 'statamic.cp.lead-magnets.')) {
            continue;
        }

        $matching = array_intersect($route->methods(), $methods);

        if ($matching === []) {
            continue;
        }

        $found[] = [
            'name' => $name,
            'method' => strtolower(reset($matching)),
            'uri' => $route->uri(),
        ];
    }

    return $found;
}

/**
 * Fill route parameters with a value the controller accepts syntactically.
 *
 * `whereNumber()` constraints make a non-numeric placeholder 404 before the
 * controller runs, which would silently turn a missing guard into a pass.
 */
function leadMagnetUrl(array $route): string
{
    return '/'.ltrim(preg_replace('/\{[^}]+\}/', '1', $route['uri']), '/');
}

it('found the routes it means to check', function () {
    // A floor, not an exact count. If the discovery above silently stops
    // matching — a renamed group, a changed Statamic CP name prefix — the
    // sweeps below would pass over an empty list and prove nothing at all.
    expect(count(leadMagnetRoutes(['POST', 'PATCH', 'PUT', 'DELETE'])))->toBeGreaterThanOrEqual(6)
        ->and(count(leadMagnetRoutes(['GET'])))->toBeGreaterThanOrEqual(4);
});

it('refuses every CP write route for a user with no lead-magnets permission', function () {
    actingAsCpUser('cp-write-nobody@example.com');

    $allowed = [];

    foreach (leadMagnetRoutes(['POST', 'PATCH', 'PUT', 'DELETE']) as $route) {
        // The verb helper, not `call()`. `withHeaders()` fills `defaultHeaders`,
        // which only the verb helpers merge into the server variables —
        // `call()` ignores them, and the request then arrives accepting HTML,
        // which turns the 403 into the 302 described above. That difference
        // cost an hour once; it does not need to cost another.
        $response = $this->{$route['method']}(
            leadMagnetUrl($route),
            [],
            ['X-Inertia' => 'true', 'Accept' => 'application/json'],
        );

        if ($response->getStatusCode() !== 403) {
            $allowed[] = sprintf(
                '%s (%s %s) answered %d, expected 403',
                $route['name'],
                strtoupper($route['method']),
                leadMagnetUrl($route),
                $response->getStatusCode()
            );
        }
    }

    expect($allowed)->toBe([], "Unguarded CP write route(s):\n".implode("\n", $allowed));
});

it('refuses every CP read route for a user with no lead-magnets permission', function () {
    actingAsCpUser('cp-read-nobody@example.com');

    $allowed = [];

    foreach (leadMagnetRoutes(['GET']) as $route) {
        $response = $this->withHeaders(['X-Inertia' => 'true', 'Accept' => 'application/json'])
            ->get(leadMagnetUrl($route));

        if ($response->getStatusCode() !== 403) {
            $allowed[] = sprintf('%s answered %d, expected 403', $route['name'], $response->getStatusCode());
        }
    }

    expect($allowed)->toBe([], "Unguarded CP read route(s):\n".implode("\n", $allowed));
});

it('lets a viewer read and still refuses every write', function () {
    actingAsCpUser('cp-viewer@example.com', ['view lead magnets']);

    $resource = makeResource();

    $this->get(cp_route('lead-magnets.resources.index'))->assertOk();
    $this->get(cp_route('lead-magnets.resources.show', $resource->id))->assertOk();

    // Reading is not managing, and the separation is the point of two
    // permissions rather than one.
    $this->getJson(cp_route('lead-magnets.resources.edit', $resource->id))->assertForbidden();

    $this->postJson(cp_route('lead-magnets.resources.store'), [
        'title' => 'Not allowed',
        'delivery_type' => 'link',
        'link_url' => 'https://example.com',
    ])->assertForbidden();
});

it('separates managing resources from managing somebody else\'s access', function () {
    actingAsCpUser('cp-author@example.com', ['view lead magnets', 'manage lead magnets']);

    $resource = makeResource();

    $grant = Grant::query()->create([
        'resource_id' => $resource->id,
        'email' => 'reader@example.com',
        'state' => 'active',
    ]);

    $this->get(cp_route('lead-magnets.resources.edit', $resource->id))->assertOk();

    // Authoring a resource does not entitle anyone to reach into a named
    // person's access.
    $this->postJson(cp_route('lead-magnets.grants.revoke', $grant->id))->assertForbidden();
});

it('never hands the file location to the browser', function () {
    actingAsCpUser('cp-manager@example.com', ['view lead magnets', 'manage lead magnets']);

    $resource = makeResource(['file_disk' => 'a-private-disk', 'file_path' => 'secret/place.txt']);

    Grant::query()->create([
        'resource_id' => $resource->id,
        'email' => 'reader@example.com',
        'state' => 'active',
    ]);

    // The show screen renders a title, a handle and the access list. It has no
    // reason to know where the file lives, and the page source of an Inertia
    // response is not a place to put storage layout.
    $this->get(cp_route('lead-magnets.resources.show', $resource->id))
        ->assertOk()
        ->assertDontSee('a-private-disk')
        ->assertDontSee('secret/place.txt');
});
