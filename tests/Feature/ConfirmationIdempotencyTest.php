<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\LeadMagnets\Events\ResourceConfirmed;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Services\GrantService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/*
 * The spec calls this out by name: a confirmation delivered twice must not
 * activate twice. It happens in the wild for reasons that have nothing to do
 * with the visitor — corporate mail scanners open every link in an incoming
 * message before the reader sees it, a queue retries a job, someone
 * double-clicks. Each of those produces a second GET of the same URL.
 */

function requestOnce(string $handle = 'warm_up', string $email = 'reader@example.com'): string
{
    makeResource(['handle' => $handle]);

    test()->post(route('lead-magnets.request'), ['email' => $email, 'resource' => $handle]);

    return tokenFromLastConfirmationMail();
}

it('activates once and delivers once when the same confirmation arrives twice', function () {
    Event::fake([ResourceConfirmed::class]);

    $token = requestOnce();

    $mailsAfterRequest = sentMailCount();

    $this->get(route('lead-magnets.confirm', ['token' => $token]))->assertOk();

    $mailsAfterFirstConfirm = sentMailCount();

    // The second, third and fourth delivery of the same confirmation.
    $this->get(route('lead-magnets.confirm', ['token' => $token]))->assertNotFound();
    $this->get(route('lead-magnets.confirm', ['token' => $token]))->assertNotFound();

    $grant = Grant::query()->with('entitlement')->sole();

    expect($grant->state())->toBe(EntitlementState::Active)
        // The token was consumed, so the link cannot be replayed at all.
        ->and($grant->token_hash)->toBeNull();

    // Exactly one delivery mail, not two.
    expect($mailsAfterFirstConfirm - $mailsAfterRequest)->toBe(1)
        ->and(sentMailCount())->toBe($mailsAfterFirstConfirm);

    Event::assertDispatchedTimes(ResourceConfirmed::class, 1);
});

it('activate() reports the winner exactly once, whatever the caller does', function () {
    Event::fake([ResourceConfirmed::class]);

    makeResource();

    $grants = app(GrantService::class);

    $grant = $grants->request(Goldnead\LeadMagnets\Models\Resource::query()->sole(), 'reader@example.com');

    // The guarantee lives in the conditional UPDATE, not in the caller's care:
    // calling activate() straight through five times on the same object still
    // yields one true and one event.
    $results = collect(range(1, 5))->map(fn () => $grants->activate($grant))->all();

    expect(array_filter($results))->toHaveCount(1);

    Event::assertDispatchedTimes(ResourceConfirmed::class, 1);
});

it('a second request for an already confirmed grant does not re-confirm it', function () {
    Event::fake([ResourceConfirmed::class]);

    $token = requestOnce();

    $this->get(route('lead-magnets.confirm', ['token' => $token]))->assertOk();

    Event::assertDispatchedTimes(ResourceConfirmed::class, 1);

    $confirmedAt = Grant::query()->with('entitlement')->sole()->confirmedAt();

    Carbon::setTestNow(Carbon::now()->addMinutes(5));

    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'warm_up',
    ]);

    $grant = Grant::query()->with('entitlement')->sole();

    expect($grant->state())->toBe(EntitlementState::Active)
        ->and($grant->confirmedAt()->timestamp)->toBe($confirmedAt->timestamp)
        // No new confirmation token: asking again for something already
        // confirmed re-sends the file, it does not restart the consent flow.
        ->and($grant->token_hash)->toBeNull();

    Event::assertDispatchedTimes(ResourceConfirmed::class, 1);

    Carbon::setTestNow();
});

it('refuses an unknown token the same way it refuses a used one', function () {
    requestOnce();

    $this->get(route('lead-magnets.confirm', ['token' => str_repeat('a', 64)]))
        ->assertNotFound();
});

it('does not confirm a token whose window has closed', function () {
    Event::fake([ResourceConfirmed::class]);

    config()->set('lead-magnets.requests.confirmation_ttl_hours', 1);

    $token = requestOnce();

    Carbon::setTestNow(Carbon::now()->addHours(2));

    $this->get(route('lead-magnets.confirm', ['token' => $token]))
        ->assertOk()
        ->assertSee('data-state="lapsed"', false);

    expect(Grant::query()->with('entitlement')->sole()->state())->toBe(EntitlementState::Pending);

    Event::assertNotDispatched(ResourceConfirmed::class);

    Carbon::setTestNow();
});
