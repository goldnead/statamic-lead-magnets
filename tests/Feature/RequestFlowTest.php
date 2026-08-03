<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\LeadMagnets\Events\ResourceConfirmed;
use Goldnead\LeadMagnets\Events\ResourceDelivered;
use Goldnead\LeadMagnets\Events\ResourceRequested;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Support\LeadMagnetSubject;
use Illuminate\Support\Facades\Event;

it('records a pending grant and mails a confirmation', function () {
    Event::fake([ResourceRequested::class, ResourceConfirmed::class]);

    makeResource();

    $this->post(route('lead-magnets.request'), [
        'email' => 'Reader@Example.com',
        'resource' => 'warm_up',
    ])->assertRedirect();

    $grant = Grant::query()->with('entitlement')->sole();

    // Normalised on the way in, so the unique index means what it says.
    expect($grant->email)->toBe('reader@example.com')
        ->and($grant->state())->toBe(EntitlementState::Pending)
        ->and($grant->confirmedAt())->toBeNull();

    // The token is never stored in the clear.
    expect($grant->token_hash)->toHaveLength(64);

    Event::assertDispatched(ResourceRequested::class);
    Event::assertNotDispatched(ResourceConfirmed::class);

    expect(sentMailCount())->toBe(1)
        ->and(tokenFromLastConfirmationMail())->not->toBeNull();
});

it('writes the grant into entitlements, addressed to the contact and not to a user', function () {
    makeResource(['handle' => 'instant', 'requires_confirmation' => false]);

    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'instant',
    ]);

    $entitlement = Grant::query()->with('entitlement')->sole()->entitlement;

    expect($entitlement)->not->toBeNull()
        ->and($entitlement->product_slug)->toBe('instant')
        ->and($entitlement->source)->toBe('lead_magnet')
        ->and($entitlement->subject_type)->toBe('lead-magnet-contact')
        // The address itself is never written into entitlements: that column is
        // 64 characters wide and an email may be 254, and the address already
        // lives on the grant row.
        ->and($entitlement->subject_id)->toBe(LeadMagnetSubject::id('reader@example.com'))
        ->and($entitlement->subject_id)->not->toContain('@');
});

it('activates and delivers immediately when the resource wants no confirmation', function () {
    Event::fake([ResourceConfirmed::class, ResourceDelivered::class]);

    makeResource(['handle' => 'instant', 'requires_confirmation' => false]);

    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'instant',
    ])->assertRedirect();

    $grant = Grant::query()->with('entitlement')->sole();

    expect($grant->state())->toBe(EntitlementState::Active)
        ->and($grant->confirmedAt())->not->toBeNull()
        ->and($grant->delivered_at)->not->toBeNull();

    Event::assertDispatched(ResourceConfirmed::class);
    Event::assertDispatched(ResourceDelivered::class);

    expect(downloadUrlFromLastDeliveryMail())->not->toBeNull();
});

it('delivers exactly one mail for a first activation', function () {
    // Delivery hangs off the entitlements event now. A manager that also
    // delivered would put two identical download links in the same inbox, and
    // no other assertion in the suite would notice.
    makeResource(['handle' => 'instant', 'requires_confirmation' => false]);

    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'instant',
    ]);

    expect(sentMailCount())->toBe(1);
});

it('asking twice leaves one grant and one entitlement', function () {
    makeResource();

    foreach ([1, 2, 3] as $ignored) {
        $this->post(route('lead-magnets.request'), [
            'email' => 'reader@example.com',
            'resource' => 'warm_up',
        ]);
    }

    expect(Grant::query()->count())->toBe(1)
        ->and(Entitlement::query()->count())->toBe(1);
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
    ])->assertOk()->assertJsonPath('data.state', EntitlementState::Pending->value);
});
