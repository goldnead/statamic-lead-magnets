<?php

use Goldnead\LeadMagnets\Events\ResourceConfirmed;
use Goldnead\LeadMagnets\GrantState;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Services\DownloadLink;
use Goldnead\LeadMagnets\Services\GrantService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\User;

/*
 * The grant lifecycle this addon owns, because statamic-entitlements does not
 * exist. See the README section "Grant state" for why that is a deliberate
 * deviation from the platform's target architecture rather than an oversight.
 */

it('knows exactly four states and no more', function () {
    expect(GrantState::ALL)->toBe(['pending', 'active', 'revoked', 'expired'])
        ->and(GrantState::isKnown('somethingelse'))->toBeFalse();
});

it('revoking is terminal for a form submission', function () {
    $resource = makeResource();
    $grants = app(GrantService::class);

    $grant = $grants->request($resource, 'reader@example.com');
    $grants->activate($grant);
    $grants->revoke($grant);

    expect($grant->fresh()->state)->toBe(GrantState::REVOKED);

    // Asking again does not overturn a moderation decision. If it did, anyone
    // who knows the address could undo it from a public form.
    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'warm_up',
    ])->assertRedirect();

    expect(Grant::query()->sole()->state)->toBe(GrantState::REVOKED);
});

it('revoking clears the confirmation token so a pending link cannot be redeemed later', function () {
    makeResource();

    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'warm_up',
    ]);

    $token = tokenFromLastConfirmationMail();

    app(GrantService::class)->revoke(Grant::query()->sole());

    $this->get(route('lead-magnets.confirm', ['token' => $token]))->assertNotFound();

    expect(Grant::query()->sole()->state)->toBe(GrantState::REVOKED);
});

it('reinstating restores access without asking for a second confirmation', function () {
    Event::fake([ResourceConfirmed::class]);

    $resource = makeResource(['requires_confirmation' => false, 'grant_ttl_days' => 30]);
    $grants = app(GrantService::class);

    $grant = $grants->request($resource, 'reader@example.com');
    $grant = Grant::query()->with('resource')->sole();

    $confirmedAt = $grant->confirmed_at;

    $grants->revoke($grant);
    $grants->reinstate($grant);

    $grant->refresh();

    expect($grant->state)->toBe(GrantState::ACTIVE)
        ->and($grant->revoked_at)->toBeNull()
        // The address was already proven. Making somebody confirm twice
        // because an editor changed their mind is a support problem dressed
        // up as diligence.
        ->and($grant->confirmed_at->timestamp)->toBe($confirmedAt->timestamp);

    Event::assertDispatchedTimes(ResourceConfirmed::class, 1);
});

it('reinstating a lapsed grant gives it a fresh lifetime', function () {
    $resource = makeResource(['requires_confirmation' => false, 'grant_ttl_days' => 1]);
    $grants = app(GrantService::class);

    $grants->request($resource, 'reader@example.com');

    Carbon::setTestNow(Carbon::now()->addDays(3));

    $grant = Grant::query()->with('resource')->sole();

    expect($grant->hasLapsed())->toBeTrue();

    $grants->reinstate($grant);

    expect($grant->fresh()->hasLapsed())->toBeFalse();

    Carbon::setTestNow();
});

it('the sweep marks lapsed grants expired and touches nothing else', function () {
    $resource = makeResource(['requires_confirmation' => false, 'grant_ttl_days' => 1]);
    $grants = app(GrantService::class);

    $grants->request($resource, 'lapsing@example.com');
    $grants->request($resource, 'fine@example.com');

    Grant::query()->where('email', 'fine@example.com')->update(['expires_at' => null]);

    Carbon::setTestNow(Carbon::now()->addDays(2));

    expect($grants->sweepExpired())->toBe(1)
        ->and(Grant::query()->where('email', 'lapsing@example.com')->sole()->state)->toBe(GrantState::EXPIRED)
        ->and(Grant::query()->where('email', 'fine@example.com')->sole()->state)->toBe(GrantState::ACTIVE);

    Carbon::setTestNow();
});

it('the console command runs the sweep', function () {
    $resource = makeResource(['requires_confirmation' => false, 'grant_ttl_days' => 1]);

    app(GrantService::class)->request($resource, 'reader@example.com');

    Carbon::setTestNow(Carbon::now()->addDays(2));

    $this->artisan('lead-magnets:sweep')->assertSuccessful();

    expect(Grant::query()->sole()->state)->toBe(GrantState::EXPIRED);

    Carbon::setTestNow();
});

it('an editor can revoke, reinstate and re-send from the Control Panel', function () {
    Gate::before(fn () => true);

    $editor = User::make()->email('grant-editor@example.com');
    $editor->save();
    $this->actingAs($editor);

    $resource = makeResource(['requires_confirmation' => false]);
    $grant = app(GrantService::class)->request($resource, 'reader@example.com');
    $grant = Grant::query()->sole();

    $before = sentMailCount();

    $this->post(cp_route('lead-magnets.grants.resend', $grant->id))->assertRedirect();

    expect(sentMailCount())->toBe($before + 1);

    $this->post(cp_route('lead-magnets.grants.revoke', $grant->id))->assertRedirect();
    expect($grant->fresh()->state)->toBe(GrantState::REVOKED);

    // A revoked grant is not deliverable, and the refusal says so at the
    // screen rather than pretending a mail went out.
    $this->post(cp_route('lead-magnets.grants.resend', $grant->id))
        ->assertSessionHasErrors('grant');

    $this->post(cp_route('lead-magnets.grants.reinstate', $grant->id))->assertRedirect();
    expect($grant->fresh()->state)->toBe(GrantState::ACTIVE);
});

it('re-sending mints a new link and leaves the old one working until it expires', function () {
    $resource = makeResource(['requires_confirmation' => false]);

    app(GrantService::class)->request($resource, 'reader@example.com');

    $grant = Grant::query()->with('resource')->sole();

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
