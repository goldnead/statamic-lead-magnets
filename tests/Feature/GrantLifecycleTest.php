<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\LeadMagnets\Events\ResourceConfirmed;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Services\DownloadLink;
use Goldnead\LeadMagnets\Services\GrantService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\User;

/*
 * The lifecycle, now that it belongs to goldnead/statamic-entitlements.
 *
 * Six states arrive with that package and this addon writes three of them:
 * Pending when a request is parked, Active when the address is proven, Revoked
 * when an editor withdraws access. Expired is written by nobody — it is derived
 * from `expires_at` by the resolver, which is why the 1.x sweep is gone.
 * Scheduled and GracePeriod have no writer here either, and are read all the
 * same, because an operator can produce both from the entitlements screen and a
 * download gate that did not understand them would be wrong in both directions:
 * serving a grant that has not started, refusing one inside its grace period.
 */

function grantFor(array $resourceAttributes = [], string $email = 'reader@example.com'): Grant
{
    $resource = makeResource($resourceAttributes);

    return makeGrant($resource, $email);
}

it('reads all six entitlement states and writes only the three it owns', function () {
    $grant = grantFor(['requires_confirmation' => false]);

    expect($grant->state())->toBe(EntitlementState::Active);

    // Written by this addon.
    app(GrantService::class)->revoke($grant, 'Test');
    expect($grant->fresh()->load('entitlement')->state())->toBe(EntitlementState::Revoked);

    // Derived, never written: the stored status still says active.
    $second = grantFor(['handle' => 'second', 'requires_confirmation' => false], 'other@example.com');
    $second->entitlement->forceFill(['expires_at' => now()->subDay()])->save();

    $second = Grant::query()->with('entitlement')->find($second->id);

    expect($second->state())->toBe(EntitlementState::Expired)
        ->and($second->entitlement->status)->toBe(EntitlementState::Active->value);
});

it('serves a grant inside a grace period and refuses one that has not started', function () {
    $grace = grantFor(['handle' => 'grace', 'requires_confirmation' => false], 'grace@example.com');

    $grace->entitlement->forceFill(['expires_at' => now()->subDay()])->save();
    Entitlements::enterGracePeriod($grace->entitlement, now()->addDays(3));

    $grace = Grant::query()->with(['entitlement', 'resource'])->find($grace->id);

    expect($grace->state())->toBe(EntitlementState::GracePeriod)
        ->and($grace->isRedeemable())->toBeTrue();

    $this->get(app(DownloadLink::class)->for($grace))->assertOk();

    $scheduled = grantFor(['handle' => 'later', 'requires_confirmation' => false], 'later@example.com');

    $scheduled->entitlement->forceFill(['starts_at' => now()->addWeek()])->save();

    $scheduled = Grant::query()->with(['entitlement', 'resource'])->find($scheduled->id);

    expect($scheduled->state())->toBe(EntitlementState::Scheduled)
        ->and($scheduled->isRedeemable())->toBeFalse();

    $this->get(app(DownloadLink::class)->for($scheduled))->assertForbidden();
});

it('revoking is terminal for a form submission', function () {
    $grant = grantFor(['requires_confirmation' => false]);

    app(GrantService::class)->revoke($grant, 'Moderation');

    expect($grant->fresh()->load('entitlement')->state())->toBe(EntitlementState::Revoked);

    // Asking again does not overturn a moderation decision. If it did, anyone
    // who knows the address could undo it from a public form.
    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'warm_up',
    ])->assertRedirect();

    expect(Grant::query()->with('entitlement')->sole()->state())->toBe(EntitlementState::Revoked);
});

it('records a reason with every revocation', function () {
    $grant = grantFor(['requires_confirmation' => false]);

    app(GrantService::class)->revoke($grant, 'Chargeback on order 4711');

    $entitlement = $grant->fresh()->load('entitlement')->entitlement;

    // The reason is entitlements' rule, not this addon's. Version 1.x had a
    // `revoked_at` nothing ever set and no reason at all; a revocation nobody
    // can explain six months later gets undone by whoever is on support.
    expect($entitlement->revoked_reason)->toBe('Chargeback on order 4711')
        ->and($entitlement->revoked_at)->not->toBeNull();
});

it('revoking clears the confirmation token so a pending link cannot be redeemed later', function () {
    makeResource();

    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'warm_up',
    ]);

    $token = tokenFromLastConfirmationMail();

    app(GrantService::class)->revoke(Grant::query()->with('entitlement')->sole(), 'Test');

    $this->get(route('lead-magnets.confirm', ['token' => $token]))->assertNotFound();

    expect(Grant::query()->with('entitlement')->sole()->state())->toBe(EntitlementState::Revoked);
});

it('reinstating restores access without asking for a second confirmation', function () {
    Event::fake([ResourceConfirmed::class]);

    $resource = makeResource(['requires_confirmation' => false, 'grant_ttl_days' => 30]);
    $grants = app(GrantService::class);

    $grants->request($resource, 'reader@example.com');

    $grant = Grant::query()->with(['resource', 'entitlement'])->sole();
    $confirmedAt = $grant->confirmedAt();

    $grants->revoke($grant, 'Mistake');
    $grants->reinstate(Grant::query()->with(['resource', 'entitlement'])->sole());

    $grant = Grant::query()->with(['resource', 'entitlement'])->sole();

    expect($grant->state())->toBe(EntitlementState::Active)
        ->and($grant->revokedAt())->toBeNull()
        // The address was already proven. Making somebody confirm twice
        // because an editor changed their mind is a support problem dressed
        // up as diligence.
        ->and($grant->confirmedAt()->timestamp)->toBe($confirmedAt->timestamp);

    Event::assertDispatchedTimes(ResourceConfirmed::class, 1);
});

it('reinstating a lapsed grant gives it a fresh lifetime', function () {
    $resource = makeResource(['requires_confirmation' => false, 'grant_ttl_days' => 1]);
    $grants = app(GrantService::class);

    $grants->request($resource, 'reader@example.com');

    Carbon::setTestNow(Carbon::now()->addDays(3));

    $grant = Grant::query()->with(['resource', 'entitlement'])->sole();

    expect($grant->hasLapsed())->toBeTrue();

    $grants->reinstate($grant);

    expect(Grant::query()->with('entitlement')->sole()->hasLapsed())->toBeFalse();

    Carbon::setTestNow();
});

it('a fresh request after the window closed opens a second access period', function () {
    $resource = makeResource(['grant_ttl_days' => 1]);
    $grants = app(GrantService::class);

    $grants->request($resource, 'reader@example.com');
    $grants->activate(Grant::query()->with(['resource', 'entitlement'])->sole());

    Carbon::setTestNow(Carbon::now()->addDays(3));

    expect(Grant::query()->with('entitlement')->sole()->state())->toBe(EntitlementState::Expired);

    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'warm_up',
    ])->assertRedirect();

    $grant = Grant::query()->with('entitlement')->sole();

    // One grant row, two entitlements. The expired one is a true record of an
    // access period that happened; overwriting it would lose that, and
    // entitlements answers over all of a subject's grants as an OR, so a second
    // row is the shape it expects.
    expect(Grant::query()->count())->toBe(1)
        ->and(Entitlement::query()->count())->toBe(2)
        ->and($grant->attempt)->toBe(2)
        ->and($grant->state())->toBe(EntitlementState::Pending);

    Carbon::setTestNow();
});

it('the sweep clears tokens whose window closed and touches nothing else', function () {
    config()->set('lead-magnets.requests.confirmation_ttl_hours', 24);

    makeResource();
    makeResource(['handle' => 'other']);

    $this->post(route('lead-magnets.request'), ['email' => 'lapsing@example.com', 'resource' => 'warm_up']);

    Carbon::setTestNow(Carbon::now()->addDays(2));

    $this->post(route('lead-magnets.request'), ['email' => 'fresh@example.com', 'resource' => 'other']);

    expect(app(GrantService::class)->sweepExpiredTokens())->toBe(1)
        ->and(Grant::query()->where('email', 'lapsing@example.com')->sole()->token_hash)->toBeNull()
        ->and(Grant::query()->where('email', 'fresh@example.com')->sole()->token_hash)->not->toBeNull();

    Carbon::setTestNow();
});

it('the console command runs the sweep', function () {
    config()->set('lead-magnets.requests.confirmation_ttl_hours', 1);

    makeResource();

    $this->post(route('lead-magnets.request'), ['email' => 'reader@example.com', 'resource' => 'warm_up']);

    Carbon::setTestNow(Carbon::now()->addHours(2));

    $this->artisan('lead-magnets:sweep')->assertSuccessful();

    expect(Grant::query()->sole()->token_hash)->toBeNull();

    Carbon::setTestNow();
});

it('an editor can revoke, reinstate and re-send from the Control Panel', function () {
    Gate::before(fn () => true);

    $editor = User::make()->email('grant-editor@example.com');
    $editor->save();
    $this->actingAs($editor);

    $resource = makeResource(['requires_confirmation' => false]);
    app(GrantService::class)->request($resource, 'reader@example.com');
    $grant = Grant::query()->sole();

    $before = sentMailCount();

    $this->post(cp_route('lead-magnets.grants.resend', $grant->id))->assertRedirect();

    expect(sentMailCount())->toBe($before + 1);

    $this->post(cp_route('lead-magnets.grants.revoke', $grant->id))->assertRedirect();

    $revoked = Grant::query()->with('entitlement')->sole();

    expect($revoked->state())->toBe(EntitlementState::Revoked)
        // No reason was typed, so the fallback records who did it. Better than
        // the blank entitlements refuses outright.
        ->and($revoked->entitlement->revoked_reason)->toContain('grant-editor@example.com');

    // A revoked grant is not deliverable, and the refusal says so at the
    // screen rather than pretending a mail went out.
    $this->post(cp_route('lead-magnets.grants.resend', $grant->id))
        ->assertSessionHasErrors('grant');

    $this->post(cp_route('lead-magnets.grants.reinstate', $grant->id))->assertRedirect();

    expect(Grant::query()->with('entitlement')->sole()->state())->toBe(EntitlementState::Active);
});

it('keeps an editor-supplied revocation reason', function () {
    Gate::before(fn () => true);

    $editor = User::make()->email('reasoned@example.com');
    $editor->save();
    $this->actingAs($editor);

    $resource = makeResource(['requires_confirmation' => false]);
    app(GrantService::class)->request($resource, 'reader@example.com');

    $grant = Grant::query()->sole();

    $this->post(cp_route('lead-magnets.grants.revoke', $grant->id), ['reason' => 'Asked to be removed'])
        ->assertRedirect();

    expect(Grant::query()->with('entitlement')->sole()->entitlement->revoked_reason)
        ->toBe('Asked to be removed');
});

it('re-sending mints a new link and leaves the old one working until it expires', function () {
    $resource = makeResource(['requires_confirmation' => false]);

    app(GrantService::class)->request($resource, 'reader@example.com');

    $grant = Grant::query()->with(['resource', 'entitlement'])->sole();

    $first = app(DownloadLink::class)->for($grant);

    Carbon::setTestNow(Carbon::now()->addMinutes(5));

    $second = app(DownloadLink::class)->for($grant);

    expect($second)->not->toBe($first);

    // Both verify: a re-send is a convenience, not a revocation. Revoking is
    // what invalidates a link, and it invalidates every one of them at once.
    $this->get($first)->assertOk();
    $this->get($second)->assertOk();

    Carbon::setTestNow();
});
