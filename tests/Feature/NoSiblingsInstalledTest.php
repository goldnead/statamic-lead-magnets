<?php

use Goldnead\Activity\Facades\Activity;
use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\LeadMagnets\GrantState;
use Goldnead\LeadMagnets\Integrations\SiblingBridges;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Suppression\Facades\SuppressionGate;

/*
 * The claim this file exists to defend: lead-magnets works with no sibling
 * addon installed at all.
 *
 * The spec names it as the testcase that proves the coupling is really
 * optional. It is enforced structurally rather than by mocking — none of the
 * five siblings appears in composer.json, in `require` or in `require-dev`, so
 * the entire suite already runs without them. This file makes that explicit,
 * so a future contributor who adds one to `require-dev` for convenience gets a
 * red test explaining why they must not.
 */

it('has no sibling addon installed', function () {
    foreach ([
        LeadHub::class,
        SubscriptionService::class,
        EmailTemplates::class,
        SuppressionGate::class,
        Activity::class,
    ] as $class) {
        expect(class_exists($class))->toBeFalse(
            $class.' is installed. It must not be: this suite is the proof that '
            .'the addon works without it. Cover the bridge with a fixture stub instead.'
        );
    }
});

it('never marks the bridges booted when nothing is installed', function () {
    expect(app(SiblingBridges::class)->booted())->toBeFalse();
});

it('runs the whole flow — request, confirm, download — on its own', function () {
    makeResource();

    // 1. Request. Its own endpoint, its own grant row.
    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'warm_up',
    ])->assertRedirect();

    expect(Grant::query()->sole()->state)->toBe(GrantState::PENDING);

    // 2. Confirm. Its own mail, carrying its own token.
    $token = tokenFromLastConfirmationMail();

    expect($token)->not->toBeNull();

    $this->get(route('lead-magnets.confirm', ['token' => $token]))->assertOk();

    expect(Grant::query()->sole()->state)->toBe(GrantState::ACTIVE);

    // 3. Download. Its own signed route, its own audit row.
    $url = downloadUrlFromLastDeliveryMail();

    expect($url)->not->toBeNull();

    $this->get($url)->assertOk()->assertDownload();

    $grant = Grant::query()->sole();

    expect($grant->download_count)->toBe(1)
        ->and($grant->downloads()->count())->toBe(1)
        // No contact was resolved, because there is no contact store — and
        // nothing about the grant is worse for it.
        ->and($grant->contact_id)->toBeNull();
});

it('sends to an address no suppression list could have cleared', function () {
    // Without the suppression addon the gate fails open, deliberately: the
    // alternative is an addon that delivers nothing until an optional package
    // is installed.
    makeResource(['handle' => 'instant', 'requires_confirmation' => false]);

    $this->post(route('lead-magnets.request'), [
        'email' => 'never-checked@example.com',
        'resource' => 'instant',
    ]);

    expect(Grant::query()->sole()->delivered_at)->not->toBeNull();
});
