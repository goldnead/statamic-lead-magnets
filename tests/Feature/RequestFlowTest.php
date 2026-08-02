<?php

use Goldnead\LeadMagnets\Events\ResourceConfirmed;
use Goldnead\LeadMagnets\Events\ResourceDelivered;
use Goldnead\LeadMagnets\Events\ResourceRequested;
use Goldnead\LeadMagnets\GrantState;
use Goldnead\LeadMagnets\Models\Grant;
use Illuminate\Support\Facades\Event;

it('records a pending grant and mails a confirmation', function () {
    Event::fake([ResourceRequested::class, ResourceConfirmed::class]);

    $resource = makeResource();

    $this->post(route('lead-magnets.request'), [
        'email' => 'Reader@Example.com',
        'resource' => 'warm_up',
    ])->assertRedirect();

    $grant = Grant::query()->sole();

    // Normalised on the way in, so the unique index means what it says.
    expect($grant->email)->toBe('reader@example.com')
        ->and($grant->state)->toBe(GrantState::PENDING)
        ->and($grant->confirmed_at)->toBeNull();

    // The token is never stored in the clear.
    expect($grant->token_hash)->toHaveLength(64);

    Event::assertDispatched(ResourceRequested::class);
    Event::assertNotDispatched(ResourceConfirmed::class);

    expect(sentMailCount())->toBe(1)
        ->and(tokenFromLastConfirmationMail())->not->toBeNull();
});

it('activates and delivers immediately when the resource wants no confirmation', function () {
    Event::fake([ResourceConfirmed::class, ResourceDelivered::class]);

    makeResource(['handle' => 'instant', 'requires_confirmation' => false]);

    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'instant',
    ])->assertRedirect();

    $grant = Grant::query()->sole();

    expect($grant->state)->toBe(GrantState::ACTIVE)
        ->and($grant->confirmed_at)->not->toBeNull()
        ->and($grant->delivered_at)->not->toBeNull();

    Event::assertDispatched(ResourceConfirmed::class);
    Event::assertDispatched(ResourceDelivered::class);

    expect(downloadUrlFromLastDeliveryMail())->not->toBeNull();
});

it('asking twice leaves one grant', function () {
    makeResource();

    foreach ([1, 2, 3] as $ignored) {
        $this->post(route('lead-magnets.request'), [
            'email' => 'reader@example.com',
            'resource' => 'warm_up',
        ]);
    }

    expect(Grant::query()->count())->toBe(1);
});

it('a filled honeypot looks exactly like a success and records nothing', function () {
    makeResource();

    $this->post(route('lead-magnets.request'), [
        'email' => 'bot@example.com',
        'resource' => 'warm_up',
        'website' => 'http://spam.example',
    ])->assertRedirect();

    expect(Grant::query()->count())->toBe(0)
        ->and(sentMailCount())->toBe(0);
});

it('refuses an unpublished resource', function () {
    makeResource(['handle' => 'draft_one', 'published' => false]);

    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'draft_one',
    ])->assertNotFound();

    expect(Grant::query()->count())->toBe(0);
});

it('validates the address', function () {
    makeResource();

    $this->post(route('lead-magnets.request'), [
        'email' => 'not-an-address',
        'resource' => 'warm_up',
    ])->assertSessionHasErrors('email');
});

it('answers JSON when asked for it', function () {
    makeResource();

    $this->postJson(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'warm_up',
    ])->assertOk()->assertJsonPath('data.state', GrantState::PENDING);
});
