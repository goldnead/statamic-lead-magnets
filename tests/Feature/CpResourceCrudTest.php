<?php

use Goldnead\LeadMagnets\GrantState;
use Goldnead\LeadMagnets\Models\Download;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\User;

beforeEach(function () {
    Gate::before(fn () => true);

    $manager = User::make()->email('cp-crud@example.com');
    $manager->save();

    $this->actingAs($manager);
});

it('lists resources with the counts an editor came for', function () {
    $resource = makeResource();

    Grant::query()->create(['resource_id' => $resource->id, 'email' => 'a@example.com', 'state' => GrantState::ACTIVE]);
    Grant::query()->create(['resource_id' => $resource->id, 'email' => 'b@example.com', 'state' => GrantState::PENDING]);
    Grant::query()->create(['resource_id' => $resource->id, 'email' => 'c@example.com', 'state' => GrantState::REVOKED]);

    $this->get(cp_route('lead-magnets.resources.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('lead-magnets::Resources/Index')
            ->where('resources.0.active', 1)
            ->where('resources.0.pending', 1)
        );
});

it('creates a resource and derives the handle from the title', function () {
    $this->post(cp_route('lead-magnets.resources.store'), [
        'title' => 'Warm-up routine',
        'delivery_type' => 'file',
        'file_path' => 'warm-up.pdf',
    ])->assertRedirect();

    expect(Resource::query()->sole()->handle)->toBe('warm_up_routine');
});

it('refuses a handle that is already taken', function () {
    makeResource(['handle' => 'taken']);

    $this->post(cp_route('lead-magnets.resources.store'), [
        'title' => 'Another',
        'handle' => 'taken',
        'delivery_type' => 'link',
        'link_url' => 'https://example.com',
    ])->assertSessionHasErrors('handle');

    expect(Resource::query()->count())->toBe(1);
});

it('requires a file for a file resource and a URL for a link resource', function () {
    $this->post(cp_route('lead-magnets.resources.store'), [
        'title' => 'No file',
        'delivery_type' => 'file',
    ])->assertSessionHasErrors('file_path');

    $this->post(cp_route('lead-magnets.resources.store'), [
        'title' => 'No url',
        'delivery_type' => 'link',
    ])->assertSessionHasErrors('link_url');
});

it('refuses to change a handle after the fact', function () {
    $resource = makeResource(['handle' => 'settled']);

    $this->patch(cp_route('lead-magnets.resources.update', $resource->id), [
        'title' => 'Renamed',
        'handle' => 'something_else',
        'delivery_type' => 'file',
        'file_path' => 'warm-up.txt',
    ])->assertSessionHasErrors('handle');

    // The handle is what live forms name and what confirmed links were issued
    // against. Renaming it silently breaks every one of them, so it is not an
    // editable field.
    expect($resource->fresh()->handle)->toBe('settled');
});

it('clears the other delivery mode when the type changes', function () {
    $resource = makeResource(['delivery_type' => 'file', 'file_path' => 'warm-up.txt']);

    $this->patch(cp_route('lead-magnets.resources.update', $resource->id), [
        'title' => 'Warm-up routine',
        'delivery_type' => 'link',
        'link_url' => 'https://example.com/thing',
    ])->assertRedirect();

    $resource->refresh();

    expect($resource->delivery_type)->toBe('link')
        ->and($resource->link_url)->toBe('https://example.com/thing')
        // A leftover path on a link resource is a loaded gun: change the type
        // back by accident and the old file is served again.
        ->and($resource->file_path)->toBeNull();
});

it('takes the grants and the download audit with the resource', function () {
    $resource = makeResource();

    $grant = Grant::query()->create([
        'resource_id' => $resource->id,
        'email' => 'reader@example.com',
        'state' => GrantState::ACTIVE,
    ]);

    $grant->downloads()->create(['brand_id' => $grant->brand_id, 'downloaded_at' => now()]);

    $this->delete(cp_route('lead-magnets.resources.destroy', $resource->id))->assertRedirect();

    expect(Resource::query()->count())->toBe(0)
        ->and(Grant::query()->count())->toBe(0)
        // An audit row whose grant is gone is an audit nobody can read.
        ->and(Download::query()->count())->toBe(0);
});

it('404s for a resource that is not there', function () {
    $this->getJson(cp_route('lead-magnets.resources.show', 999))->assertNotFound();
    $this->getJson(cp_route('lead-magnets.resources.edit', 999))->assertNotFound();
    $this->patchJson(cp_route('lead-magnets.resources.update', 999), [])->assertNotFound();
    $this->deleteJson(cp_route('lead-magnets.resources.destroy', 999))->assertNotFound();
});
