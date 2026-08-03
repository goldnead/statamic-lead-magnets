<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Services\DownloadLink;
use Goldnead\LeadMagnets\Services\GrantService;
use Illuminate\Support\Carbon;

/*
 * The defect this file exists for, in one sentence: the deadline for confirming
 * an address is not the lifetime of the access it unlocks.
 *
 * A grant waiting for a double opt-in has two clocks that look alike and mean
 * entirely different things — "you have 72 hours to confirm" and "your access
 * lasts a year, or forever". Version 1.0.0 kept both in one column and had to
 * overwrite one with the other at the moment of activation. That was a single
 * line, and without it every confirmed access silently expired 72 hours later:
 * no error, no log entry, no failing test, just a download link that stopped
 * working three days after somebody clicked it and a support mail weeks on.
 *
 * The move to entitlements could have carried the defect across intact —
 * `claimPending()` flips the status and does not touch `expires_at`, so a
 * confirmation deadline written there would simply have stayed. It does not,
 * because the two clocks now live in two columns on two different rows: the
 * deadline is `lead_magnet_grants.confirm_expires_at` and belongs to the token,
 * the window is `entitlements.expires_at` and is written at activation and
 * never before.
 *
 * These tests assert the behaviour, not the arrangement. They would have failed
 * against the version that shipped the bug and they fail against any future one
 * that reinvents it, whatever the columns are called by then.
 */

function confirmedGrant(array $resourceAttributes = [], int $confirmationHours = 72): Grant
{
    config()->set('lead-magnets.requests.confirmation_ttl_hours', $confirmationHours);

    $resource = makeResource($resourceAttributes);

    test()->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => $resource->handle,
    ]);

    test()->get(route('lead-magnets.confirm', ['token' => tokenFromLastConfirmationMail()]));

    return Grant::query()->with(['entitlement', 'resource'])->sole();
}

it('does not leave the confirmation deadline behind as the access expiry', function () {
    // The resource sets no lifetime, so the confirmed access has none. If the
    // 72-hour confirmation window survived activation, this is the assertion
    // that catches it — before any clock is moved at all.
    $grant = confirmedGrant();

    expect($grant->state())->toBe(EntitlementState::Active)
        ->and($grant->entitlement->expires_at)->toBeNull()
        ->and($grant->accessEndsAt())->toBeNull();
});

it('still serves the file long after the confirmation window would have closed', function () {
    $grant = confirmedGrant();

    $url = app(DownloadLink::class)->for($grant);

    // Four days: past the 72-hour confirmation deadline, and nowhere near any
    // access lifetime, because this resource sets none.
    Carbon::setTestNow(Carbon::now()->addDays(4));

    expect(Grant::query()->with('entitlement')->sole()->state())->toBe(EntitlementState::Active);

    // A fresh link, because the signed link has its own shorter life. The
    // question here is whether the *access* survived, not whether a week-old
    // URL did.
    $this->get(app(DownloadLink::class)->for(Grant::query()->with(['entitlement', 'resource'])->sole()))
        ->assertOk()
        ->assertDownload();

    expect($url)->not->toBeEmpty();

    Carbon::setTestNow();
});

it('uses the resource lifetime as the access window, counted from the confirmation', function () {
    $confirmedAt = Carbon::parse('2026-03-01 12:00:00');

    Carbon::setTestNow($confirmedAt);

    $grant = confirmedGrant(['grant_ttl_days' => 365], confirmationHours: 72);

    // Not 72 hours, and not counted from the request either: a year from the
    // moment the address was proven.
    expect($grant->entitlement->expires_at->timestamp)
        ->toBe($confirmedAt->copy()->addDays(365)->timestamp);

    Carbon::setTestNow();
});

it('keeps the confirmation deadline off the entitlement while the grant is pending', function () {
    config()->set('lead-magnets.requests.confirmation_ttl_hours', 72);

    makeResource();

    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'warm_up',
    ]);

    $grant = Grant::query()->with('entitlement')->sole();

    // The deadline is on the grant, where the token is. The entitlement has no
    // window at all — which is why activation has nothing to overwrite and no
    // overwrite to forget.
    expect($grant->confirm_expires_at)->not->toBeNull()
        ->and($grant->entitlement->expires_at)->toBeNull()
        ->and($grant->state())->toBe(EntitlementState::Pending);
});

it('expires the access when the resource lifetime really does run out', function () {
    // The mirror image, so the test above cannot pass by never expiring
    // anything at all.
    $grant = confirmedGrant(['grant_ttl_days' => 7]);

    $url = app(DownloadLink::class)->for($grant);

    Carbon::setTestNow(Carbon::now()->addDays(8));

    $fresh = Grant::query()->with('entitlement')->sole();

    expect($fresh->state())->toBe(EntitlementState::Expired)
        ->and($fresh->hasLapsed())->toBeTrue();

    $this->get($url)->assertForbidden();

    Carbon::setTestNow();
});

it('never signs a link that outlives the access window written at activation', function () {
    // The ordering guard. The listener that mails the download link runs inside
    // `claimPending()`, so the window has to be in place before the claim — a
    // link signed a moment earlier would be capped by nothing.
    $grant = confirmedGrant(['grant_ttl_days' => 1, 'link_ttl' => 60 * 24 * 30]);

    $url = downloadUrlFromLastDeliveryMail();

    expect($url)->not->toBeNull();

    preg_match('/expires=(\d+)/', $url, $matches);

    expect((int) $matches[1])->toBeLessThanOrEqual($grant->entitlement->expires_at->timestamp);
});

it('reports the loser of a race and writes no window for it', function () {
    $resource = makeResource(['requires_confirmation' => true]);
    $grants = app(GrantService::class);

    $grant = $grants->request($resource, 'reader@example.com');
    $grant = Grant::query()->with(['entitlement', 'resource'])->sole();

    expect($grants->activate($grant))->toBeTrue();

    $expiresAt = $grant->fresh()->load('entitlement')->entitlement->expires_at;

    // A second caller must not touch the window of a grant it did not claim.
    // The `status = pending` guard sits on the window write as well as on the
    // claim, so the loser stops at the first statement.
    expect($grants->activate($grant))->toBeFalse()
        ->and(Grant::query()->with('entitlement')->sole()->entitlement->expires_at?->timestamp)
        ->toBe($expiresAt?->timestamp);
});
