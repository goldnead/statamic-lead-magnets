<?php

/**
 * The nav entry is there the moment the addon is installed, and the resources
 * screen was reachable before anybody had run `php artisan migrate` — it
 * answered HTTP 500. These tests reproduce that install (everything present
 * except the tables) and hold the page to an empty state plus a line in the log.
 *
 * `entitlements` gets its own case. Access state moved to a sibling addon, so
 * the listing's two counts join into a table this addon does not own: an
 * install that migrated lead-magnets and not entitlements crashes just the
 * same, and that is the half-migrated state most likely to occur.
 */

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\User;

beforeEach(function () {
    Gate::before(fn () => true);

    $this->actingAs(tap(User::make()->email('setup@example.test'))->save());
});

function dropLeadMagnetTables(): void
{
    Schema::dropIfExists('lead_magnet_downloads');
    Schema::dropIfExists('lead_magnet_grants');
    Schema::dropIfExists('lead_magnet_resources');
}

it('answers 200 on the listing when its own tables are missing', function () {
    dropLeadMagnetTables();

    $this->get(cp_route('lead-magnets.resources.index'))->assertOk();
});

it('renders the setup screen and names the missing tables', function () {
    dropLeadMagnetTables();

    $this->get(cp_route('lead-magnets.resources.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('lead-magnets::SetupRequired')
            ->where('tables', ['lead_magnet_resources', 'lead_magnet_grants'])
            ->whereNot('heading', '')
            ->whereNot('description', '')
        );
});

/**
 * The point of the guard is a readable page, not a quiet one. If this goes red
 * the addon has traded a visible 500 for a silent nothing.
 */
it('writes the reason to the log', function () {
    dropLeadMagnetTables();

    Log::spy();

    $this->get(cp_route('lead-magnets.resources.index'))->assertOk();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'statamic-lead-magnets')
            && str_contains($message, 'php artisan migrate'))
        ->once();
});

it('guards the foreign entitlements table too, with its own tables in place', function () {
    Schema::dropIfExists('entitlements');

    $this->get(cp_route('lead-magnets.resources.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('lead-magnets::SetupRequired')
            ->where('tables', ['entitlements'])
        );
});

it('names entitlements in the log when only the foreign table is gone', function () {
    Schema::dropIfExists('entitlements');

    Log::spy();

    $this->get(cp_route('lead-magnets.resources.index'))->assertOk();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'entitlements')
            && str_contains($message, 'php artisan migrate'))
        ->once();
});

it('guards the detail page, which also reads the downloads', function () {
    $resource = makeResource();

    Schema::dropIfExists('lead_magnet_downloads');

    $this->get(cp_route('lead-magnets.resources.show', $resource->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('lead-magnets::SetupRequired')
            ->where('tables', ['lead_magnet_downloads'])
        );
});

it('still renders the listing on a migrated install', function () {
    makeResource();

    $this->get(cp_route('lead-magnets.resources.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('lead-magnets::Resources/Index'));
});

it('still renders the detail page on a migrated install', function () {
    $resource = makeResource();

    $this->get(cp_route('lead-magnets.resources.show', $resource->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('lead-magnets::Resources/Show'));
});
