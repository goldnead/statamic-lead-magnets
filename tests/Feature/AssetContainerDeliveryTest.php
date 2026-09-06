<?php

use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Services\DownloadLink;
use Goldnead\LeadMagnets\Support\MagnetAssets;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\User;

/*
 * The point of this file, and the reason the file field is not "just a field":
 *
 * a resource uploaded in the Control Panel now lives in a Statamic asset
 * container. A container's whole purpose elsewhere in Statamic is to publish
 * files. Here it must do the opposite — hold a file that the web cannot reach
 * by any route but the signed one, because a lead magnet behind a double
 * opt-in that anybody can fetch by guessing its name is not gated at all.
 *
 * So every test below is one half of the same pair: the file is refused
 * publicly, and delivered over the signed route. Either half alone proves
 * nothing worth having.
 */

beforeEach(function () {
    Gate::before(fn () => true);

    $manager = User::make()->email('assets@example.com');
    $manager->save();

    $this->actingAs($manager);

    $this->assets = app(MagnetAssets::class);
});

it('creates its own container on a disk the web cannot reach', function () {
    expect(AssetContainer::findByHandle('lead_magnets'))->toBeNull();

    $container = $this->assets->ensureContainer();

    expect($container->handle())->toBe('lead_magnets')
        ->and($container->diskHandle())->toBe('lead-magnets')
        // Statamic's own reading of the same question. It only knows about a
        // `url` on the disk, which is why the addon asks a wider one below.
        ->and($container->private())->toBeTrue()
        ->and($this->assets->diskIsPublic())->toBeFalse();

    $root = realpath((string) config('filesystems.disks.lead-magnets.root'));

    expect($root)->not->toBeFalse()
        ->and(str_starts_with($root, realpath(public_path()) ?: public_path()))->toBeFalse();
});

it('makes the container once and leaves an existing one alone', function () {
    $first = $this->assets->ensureContainer();
    $first->title('Renamed by hand')->save();

    $second = $this->assets->ensureContainer();

    expect($second->title())->toBe('Renamed by hand')
        ->and(AssetContainer::all())->toHaveCount(1);
});

it('refuses the uploaded file over every public URL and serves it over the signed one', function () {
    $asset = makeMagnetAsset('warm-up.pdf', 'the gated file');

    // Statamic has no address to hand out, so nothing in a template, a feed or
    // an API response can leak one.
    expect($asset->url())->toBeNull();

    // Laravel's `/storage/{path}` route exists in this application — the
    // harness turns `serve` on for `local` the way a fresh skeleton ships it —
    // and it does not reach this file, because this file is not on that disk.
    expect(Route::has('storage.local'))->toBeTrue()
        ->and(Route::has('storage.lead-magnets'))->toBeFalse();

    // The four addresses somebody who knows the filename would try.
    foreach ([
        '/storage/warm-up.pdf',
        '/storage/lead-magnets/warm-up.pdf',
        '/assets/warm-up.pdf',
        '/warm-up.pdf',
    ] as $url) {
        expect($this->get($url)->status())->not->toBe(200, $url.' was publicly readable');
    }

    // And the other half: over the signed route, with an active grant, the
    // very same bytes come back.
    $resource = Resource::query()->create([
        'handle' => 'warm_up',
        'title' => 'Warm-up routine',
        'delivery_type' => Resource::TYPE_FILE,
        'file_path' => $asset->path(),
        'file_disk' => $this->assets->diskHandle(),
        'requires_confirmation' => false,
        'published' => true,
    ]);

    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => $resource->handle,
    ]);

    $grant = Grant::query()->with(['resource', 'entitlement'])->sole();

    $response = $this->get(app(DownloadLink::class)->for($grant))
        ->assertOk()
        ->assertDownload();

    expect($response->streamedContent())->toBe('the gated file');
});

it('says so on the form when the container sits on a disk the web can reach', function () {
    // The check has to be capable of failing, or it is decoration. Pointed at
    // Laravel's `public` disk — a URL, public visibility, `public/storage` —
    // it must fire, and the form must show it.
    config()->set('lead-magnets.assets.disk', 'public');

    expect($this->assets->diskIsPublic())->toBeTrue();

    $this->get(cp_route('lead-magnets.resources.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('diskWarning', fn ($warning) => $warning !== null));
});

it('says nothing on the form while the container is private', function () {
    expect($this->assets->diskIsPublic())->toBeFalse();

    $this->get(cp_route('lead-magnets.resources.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('diskWarning', null));
});

it('hands the form the picker preloaded with the resource that has a file', function () {
    $asset = makeMagnetAsset('warm-up.pdf');

    $resource = makeResource(['file_path' => $asset->path()]);

    $this->get(cp_route('lead-magnets.resources.edit', $resource->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('fileField.blueprint.tabs.0.sections.0.fields.0.handle', 'file_asset')
            ->where('fileField.blueprint.tabs.0.sections.0.fields.0.type', 'assets')
            ->where('fileField.blueprint.tabs.0.sections.0.fields.0.container', 'lead_magnets')
            // The stored value is a path; the fieldtype wants an id. The
            // conversion happens server-side, and this is the assertion that
            // it happened.
            ->where('fileField.values.file_asset.0', $asset->id())
        );
});

it('shows an empty picker rather than breaking for a path that is not an asset', function () {
    // No file behind it, so there is no asset to preselect. A path typed by
    // hand before the picker existed, or a file since deleted, reaches this.
    $resource = Resource::query()->create([
        'handle' => 'gone',
        'title' => 'Gone',
        'delivery_type' => Resource::TYPE_FILE,
        'file_path' => 'set-by-hand/elsewhere.pdf',
        'file_disk' => 'some-other-disk',
        'requires_confirmation' => true,
        'published' => true,
    ]);

    $this->get(cp_route('lead-magnets.resources.edit', $resource->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('fileField.values.file_asset', []));
});

it('stores the picked asset as a path on the container disk', function () {
    $asset = makeMagnetAsset('warm-up.pdf');

    $this->post(cp_route('lead-magnets.resources.store'), [
        'title' => 'Warm-up routine',
        'delivery_type' => 'file',
        'file_asset' => $asset->id(),
    ])->assertRedirect();

    $resource = Resource::query()->sole();

    expect($resource->file_path)->toBe('warm-up.pdf')
        // Never taken from the request: the disk is the container's, decided
        // here, so a resource cannot be pointed at a disk of somebody's
        // choosing by editing a form.
        ->and($resource->file_disk)->toBe('lead-magnets')
        ->and($resource->link_url)->toBeNull();
});

it('refuses an asset from another container', function () {
    $this->assets->ensureContainer();

    AssetContainer::make('public_images')->disk('public')->save();
    Storage::disk('public')->put('logo.png', 'not a lead magnet');
    Asset::make()->container('public_images')->path('logo.png')->save();

    $this->post(cp_route('lead-magnets.resources.store'), [
        'title' => 'Sneaky',
        'delivery_type' => 'file',
        'file_asset' => 'public_images::logo.png',
    ])->assertSessionHasErrors('file_asset');

    expect(Resource::query()->count())->toBe(0);
});

it('keeps the file of an existing resource when the picker comes back empty', function () {
    // The case a hand-set path leaves behind: the picker cannot show a file
    // outside the container, so it submits nothing. Emptying the column on the
    // save of an unrelated field would break a working download in silence.
    $resource = Resource::query()->create([
        'handle' => 'by_hand',
        'title' => 'By hand',
        'delivery_type' => Resource::TYPE_FILE,
        'file_path' => 'set-by-hand/elsewhere.pdf',
        'file_disk' => 'some-other-disk',
        'requires_confirmation' => true,
        'published' => true,
    ]);

    $this->patch(cp_route('lead-magnets.resources.update', $resource->id), [
        'title' => 'A new title',
        'delivery_type' => 'file',
        'file_asset' => null,
    ])->assertRedirect();

    $resource->refresh();

    expect($resource->title)->toBe('A new title')
        ->and($resource->file_path)->toBe('set-by-hand/elsewhere.pdf')
        ->and($resource->file_disk)->toBe('some-other-disk');
});

it('still takes a link resource and leaves its URL alone', function () {
    $this->post(cp_route('lead-magnets.resources.store'), [
        'title' => 'A link',
        'delivery_type' => 'link',
        'link_url' => 'https://example.com/paper.pdf',
    ])->assertRedirect();

    $resource = Resource::query()->sole();

    expect($resource->delivery_type)->toBe(Resource::TYPE_LINK)
        ->and($resource->link_url)->toBe('https://example.com/paper.pdf')
        ->and($resource->file_path)->toBeNull();
});

it('names the source that is actually delivered next to the pill', function () {
    makeResource(['handle' => 'a_file', 'title' => 'A file', 'file_path' => 'folder/warm-up.pdf']);

    // A row carrying both — nothing in the schema stops a seed or an import
    // from writing one. The download route reads `delivery_type` and nothing
    // else, so the listing names the source that type points at.
    Resource::query()->create([
        'handle' => 'both',
        'title' => 'Both',
        'delivery_type' => Resource::TYPE_LINK,
        'file_path' => 'folder/leftover.pdf',
        'link_url' => 'https://example.com/paper.pdf',
        'requires_confirmation' => true,
        'published' => true,
    ]);

    $this->get(cp_route('lead-magnets.resources.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('resources.0.delivery_source', 'warm-up.pdf')
            ->where('resources.1.delivery_source', 'https://example.com/paper.pdf')
        );
});
