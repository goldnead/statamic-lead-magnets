<?php

use Goldnead\LeadMagnets\Events\ResourceDownloaded;
use Goldnead\LeadMagnets\GrantState;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Services\DownloadLink;
use Goldnead\LeadMagnets\Services\GrantService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/*
 * The spec's two named requirements for this file: an expired link must not
 * deliver, and a tampered link must not deliver. Everything else here is the
 * same idea from another angle.
 */

function activeGrant(array $resourceAttributes = []): Grant
{
    $resource = makeResource(array_merge(['requires_confirmation' => false], $resourceAttributes));

    test()->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => $resource->handle,
    ]);

    return Grant::query()->with('resource')->sole();
}

it('serves the file over a valid signed link and audits it', function () {
    Event::fake([ResourceDownloaded::class]);

    $grant = activeGrant();

    $this->get(app(DownloadLink::class)->for($grant))
        ->assertOk()
        ->assertDownload();

    $grant->refresh();

    expect($grant->download_count)->toBe(1)
        ->and($grant->downloads()->count())->toBe(1);

    $audit = $grant->downloads()->sole();

    expect($audit->downloaded_at)->not->toBeNull()
        // The address itself is never in the audit row; the hash is enough to
        // recognise the same client without holding data the audit cannot use.
        ->and($audit->ip_hash)->toHaveLength(64);

    Event::assertDispatchedTimes(ResourceDownloaded::class, 1);
});

it('refuses an expired link', function () {
    $grant = activeGrant(['link_ttl' => 60]);

    $url = app(DownloadLink::class)->for($grant);

    // Valid right up to the deadline …
    $this->get($url)->assertOk();

    Carbon::setTestNow(Carbon::now()->addMinutes(61));

    // … and refused after it, by the signature, before the controller runs.
    $this->get($url)->assertForbidden();

    expect(Grant::query()->sole()->download_count)->toBe(1);

    Carbon::setTestNow();
});

it('refuses a link whose grant id was edited', function () {
    $first = activeGrant();

    $second = Grant::query()->create([
        'resource_id' => $first->resource_id,
        'email' => 'someone-else@example.com',
        'state' => GrantState::ACTIVE,
        'confirmed_at' => now(),
    ]);

    $url = app(DownloadLink::class)->for($first);

    $tampered = str_replace('/download/'.$first->id.'?', '/download/'.$second->id.'?', $url);

    expect($tampered)->not->toBe($url);

    $this->get($tampered)->assertForbidden();

    expect($second->fresh()->download_count)->toBe(0);
});

it('refuses a link whose expiry was pushed out', function () {
    $grant = activeGrant(['link_ttl' => 60]);

    $url = app(DownloadLink::class)->for($grant);

    preg_match('/expires=(\d+)/', $url, $matches);

    $forged = str_replace('expires='.$matches[1], 'expires='.((int) $matches[1] + 86400), $url);

    $this->get($forged)->assertForbidden();

    expect(Grant::query()->sole()->download_count)->toBe(0);
});

it('refuses a link whose signature was edited', function () {
    $grant = activeGrant();

    $url = app(DownloadLink::class)->for($grant);

    preg_match('/signature=([a-f0-9]+)/', $url, $matches);

    $forged = str_replace($matches[1], strrev($matches[1]), $url);

    $this->get($forged)->assertForbidden();
});

it('refuses an unsigned link entirely', function () {
    $grant = activeGrant();

    $this->get('/!/lead-magnets/download/'.$grant->id)->assertForbidden();
});

it('refuses a link for a revoked grant, even though the signature still verifies', function () {
    $grant = activeGrant();

    $url = app(DownloadLink::class)->for($grant);

    app(GrantService::class)->revoke($grant);

    // The signature is untouched and valid. Access is what changed, and the
    // signature was never a statement about access.
    $this->get($url)->assertForbidden();

    expect(Grant::query()->sole()->download_count)->toBe(0);
});

it('refuses a link for a pending grant', function () {
    makeResource();

    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'warm_up',
    ]);

    $grant = Grant::query()->with('resource')->sole();

    expect($grant->state)->toBe(GrantState::PENDING);

    $this->get(app(DownloadLink::class)->for($grant))->assertForbidden();
});

it('refuses once the download cap is reached', function () {
    $grant = activeGrant(['max_downloads' => 2]);

    $url = app(DownloadLink::class)->for($grant);

    $this->get($url)->assertOk();
    $this->get($url)->assertOk();
    $this->get($url)->assertForbidden();

    expect(Grant::query()->sole()->download_count)->toBe(2);
});

it('refuses once the grant itself has lapsed, without waiting for the sweep', function () {
    $grant = activeGrant(['grant_ttl_days' => 1]);

    $url = app(DownloadLink::class)->for($grant);

    Carbon::setTestNow(Carbon::now()->addDays(2));

    // The state column still says `active` — nothing has swept it — and the
    // link is refused anyway, because the gate reads the date.
    expect(Grant::query()->sole()->state)->toBe(GrantState::ACTIVE);

    $this->get($url)->assertForbidden();

    Carbon::setTestNow();
});

it('never signs a link that outlives the access it grants', function () {
    $grant = activeGrant(['grant_ttl_days' => 1, 'link_ttl' => 60 * 24 * 30]);

    $url = app(DownloadLink::class)->for($grant);

    preg_match('/expires=(\d+)/', $url, $matches);

    expect((int) $matches[1])->toBeLessThanOrEqual($grant->fresh()->expires_at->timestamp);
});

it('audits and forwards a link resource without ever serving a file', function () {
    Event::fake([ResourceDownloaded::class]);

    $grant = activeGrant([
        'handle' => 'external',
        'delivery_type' => Resource::TYPE_LINK,
        'file_path' => null,
        'link_url' => 'https://example.com/the-thing',
    ]);

    $this->get(app(DownloadLink::class)->for($grant))
        ->assertRedirect('https://example.com/the-thing');

    // Counted first, then forwarded: a redirect nobody counted is a delivery
    // nobody can prove.
    expect(Grant::query()->sole()->download_count)->toBe(1);

    Event::assertDispatchedTimes(ResourceDownloaded::class, 1);
});

it('404s when the file behind the resource is gone', function () {
    $grant = activeGrant();

    Storage::disk('lead-magnets')->delete('warm-up.txt');

    $this->get(app(DownloadLink::class)->for($grant))->assertNotFound();
});
