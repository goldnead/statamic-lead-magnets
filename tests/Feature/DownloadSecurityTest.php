<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\LeadMagnets\Events\ResourceDownloaded;
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

    return Grant::query()->with(['resource', 'entitlement'])->sole();
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

    $second = makeGrant($first->resource, 'someone-else@example.com');

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

    app(GrantService::class)->revoke($grant, 'Refunded');

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

    expect($grant->state())->toBe(EntitlementState::Pending);

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

it('refuses once the grant itself has lapsed, with nothing having run in between', function () {
    $grant = activeGrant(['grant_ttl_days' => 1]);

    $url = app(DownloadLink::class)->for($grant);

    Carbon::setTestNow(Carbon::now()->addDays(2));

    $lapsed = Grant::query()->with('entitlement')->sole();

    // No command ran, no job, no request. The stored status is still `active`
    // and the state is Expired all the same, because entitlements derives it
    // from the clock rather than from something having swept it.
    expect($lapsed->entitlement->status)->toBe(EntitlementState::Active->value)
        ->and($lapsed->state())->toBe(EntitlementState::Expired);

    $this->get($url)->assertForbidden();

    Carbon::setTestNow();
});

it('never signs a link that outlives the access it grants', function () {
    $grant = activeGrant(['grant_ttl_days' => 1, 'link_ttl' => 60 * 24 * 30]);

    $url = app(DownloadLink::class)->for($grant);

    preg_match('/expires=(\d+)/', $url, $matches);

    expect((int) $matches[1])->toBeLessThanOrEqual($grant->fresh()->load('entitlement')->accessEndsAt()->timestamp);
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

it('does not count a download whose file is gone, so the cap is not spent on a 404', function () {
    Event::fake([ResourceDownloaded::class]);

    $grant = activeGrant(['max_downloads' => 1]);

    Storage::disk('lead-magnets')->delete('warm-up.txt');

    $url = app(DownloadLink::class)->for($grant);

    $this->get($url)->assertNotFound();

    expect(Grant::query()->sole()->download_count)->toBe(0)
        ->and(Grant::query()->sole()->downloads()->count())->toBe(0);

    Event::assertNotDispatched(ResourceDownloaded::class);
});

it('stops counting once the cap is reached: no further audit row, no further counter step', function () {
    Event::fake([ResourceDownloaded::class]);

    $grant = activeGrant(['max_downloads' => 1]);

    $url = app(DownloadLink::class)->for($grant);

    $this->get($url)->assertOk();
    $this->get($url)->assertForbidden();
    $this->get($url)->assertForbidden();

    $grant->refresh();

    expect($grant->download_count)->toBe(1)
        ->and($grant->downloads()->count())->toBe(1);

    Event::assertDispatchedTimes(ResourceDownloaded::class, 1);
});

it('stops counting a multi-file list per file once that file reached the cap', function () {
    Event::fake([ResourceDownloaded::class]);

    $resource = makeResource([
        'handle' => 'capped-list',
        'title' => 'Capped',
        'requires_confirmation' => false,
        'max_downloads' => 1,
        'files' => [
            ['key' => 'aaaa1111', 'path' => 'capped/a.txt', 'label' => 'A', 'group' => null],
            ['key' => 'bbbb2222', 'path' => 'capped/b.txt', 'label' => 'B', 'group' => null],
        ],
        'file_path' => 'capped/a.txt',
    ]);

    Storage::disk('lead-magnets')->put('capped/a.txt', 'a');
    Storage::disk('lead-magnets')->put('capped/b.txt', 'b');

    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => $resource->handle,
    ]);

    $grant = Grant::query()->with(['resource', 'entitlement'])->sole();
    $downloads = app(DownloadLink::class);

    $this->get($downloads->forFile($grant, 'aaaa1111'))->assertOk();
    $this->get($downloads->forFile($grant, 'aaaa1111'))->assertForbidden();
    $this->get($downloads->forFile($grant, 'bbbb2222'))->assertOk();

    $grant->refresh();

    expect($grant->downloads()->where('file_key', 'aaaa1111')->count())->toBe(1)
        ->and($grant->downloads()->where('file_key', 'bbbb2222')->count())->toBe(1)
        ->and($grant->download_count)->toBe(2);

    Event::assertDispatchedTimes(ResourceDownloaded::class, 2);
});

it('does not count a link resource that has no URL to forward to', function () {
    Event::fake([ResourceDownloaded::class]);

    $grant = activeGrant([
        'handle' => 'external-empty',
        'delivery_type' => Resource::TYPE_LINK,
        'file_path' => null,
        'link_url' => 'https://example.com/placeholder',
    ]);

    // The URL is taken away after the grant exists, as an editor could do.
    Resource::query()->whereKey($grant->resource_id)->update(['link_url' => null]);

    $this->get(app(DownloadLink::class)->for($grant))->assertNotFound();

    expect(Grant::query()->sole()->download_count)->toBe(0)
        ->and(Grant::query()->sole()->downloads()->count())->toBe(0);

    Event::assertNotDispatched(ResourceDownloaded::class);
});
