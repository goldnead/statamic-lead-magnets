<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\LeadMagnets\Integrations\SiblingBridges;
use Goldnead\LeadMagnets\LeadMagnetsManager;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeEmailTemplate;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeEmailTemplatesFacade;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeMailingListRepository;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeMarketingService;
use Goldnead\LeadMagnets\Tests\Fixtures\SiblingStubs;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/*
 * One confirmation for the file and the list.
 *
 * A resource can say "the file comes with the newsletter": the form and the
 * confirmation mail carry a disclosure sentence, and confirming the address is
 * then also the double opt-in for the list. marketing's own confirmation is
 * skipped for that one subscription, and the consent it records names what the
 * reader was shown, when they asked, when they confirmed and from which
 * resource.
 *
 * Three things keep that honest, and each has a test below:
 *
 *  - The disclosure has to have been in front of the reader. It is copied onto
 *    the grant when the confirmation mail goes out, and that copy is what the
 *    consent record carries, whatever the resource says later.
 *  - Opening the link is not agreeing. A mail scanner fetches every URL in a
 *    message; for a coupled grant GET shows a button and changes nothing, and
 *    only the POST confirms. marketing holds its own link to the same rule.
 *  - Nobody else may confirm on the reader's behalf. An editor reinstating a
 *    pending grant activates the file, and the list falls back to marketing's
 *    own double opt-in.
 */

const DISCLOSURE = 'Mit der Anforderung meldest du dich zum Newsletter an. Abmelden geht jederzeit, das Freebie bleibt.';

beforeEach(function () {
    SiblingStubs::bindAll();
    FakeMailingListRepository::$lists['newsletter'] = (object) ['handle' => 'newsletter'];

    app()->forgetInstance(SiblingBridges::class);
    app(SiblingBridges::class)->boot(app('events'));
});

function coupledResource(array $attributes = []): Goldnead\LeadMagnets\Models\Resource
{
    return makeResource(array_merge([
        'handle' => 'vowel_guide',
        'title' => 'Vowel Guide',
        'requires_confirmation' => true,
        'marketing_list' => 'newsletter',
        'list_via_confirmation' => true,
        'list_consent_text' => DISCLOSURE,
    ], $attributes));
}

function lastMailBody(): string
{
    $messages = app('mailer')->getSymfonyTransport()->messages();

    return quoted_printable_decode($messages[count($messages) - 1]->getMessage()->toString());
}

it('sends exactly one confirmation, and it carries the disclosure', function () {
    coupledResource();

    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'vowel_guide',
    ])->assertRedirect();

    expect(sentMailCount())->toBe(1)
        ->and(lastMailBody())->toContain(DISCLOSURE)
        ->and(FakeMarketingService::$subscriptions)->toBe([]);
});

it('copies the disclosure onto the grant when the confirmation goes out', function () {
    Carbon::setTestNow('2026-10-04 18:00:00');

    $resource = coupledResource();

    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');

    $consent = Grant::query()->sole()->meta['list_consent'] ?? null;

    expect($consent)->toBe([
        'list' => 'newsletter',
        'text' => DISCLOSURE,
        'source' => 'lead-magnets:vowel_guide',
        'requested_at' => '2026-10-04T18:00:00+00:00',
    ]);
});

it('does not confirm on GET for a coupled grant, so a link scanner cannot consent', function () {
    coupledResource();

    app(LeadMagnetsManager::class)->request(
        Goldnead\LeadMagnets\Models\Resource::query()->sole(),
        'reader@example.com',
    );

    $token = tokenFromLastConfirmationMail();
    $mails = sentMailCount();

    $this->get(route('lead-magnets.confirm', ['token' => $token]))
        ->assertOk()
        ->assertSee(route('lead-magnets.confirm.store', ['token' => $token]), false)
        ->assertSee(DISCLOSURE);

    expect(Grant::query()->with('entitlement')->sole()->state())->toBe(EntitlementState::Pending)
        ->and(sentMailCount())->toBe($mails)
        ->and(FakeMarketingService::$subscriptions)->toBe([]);
});

it('confirms on POST: the file is delivered and the list is subscribed without a second confirmation', function () {
    Carbon::setTestNow('2026-10-04 18:00:00');
    $resource = coupledResource();
    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');
    $token = tokenFromLastConfirmationMail();

    Carbon::setTestNow('2026-10-04 18:05:00');

    $this->post(route('lead-magnets.confirm.store', ['token' => $token]))->assertOk();

    $grant = Grant::query()->with('entitlement')->sole();

    expect($grant->state())->toBe(EntitlementState::Active)
        ->and($grant->delivered_at)->not->toBeNull()
        // The confirmation and the delivery, nothing from the list.
        ->and(sentMailCount())->toBe(2)
        ->and(FakeMarketingService::$subscriptions)->toHaveCount(1);

    $subscription = FakeMarketingService::$subscriptions[0];

    expect($subscription['handle'])->toBe('newsletter')
        ->and($subscription['email'])->toBe('reader@example.com')
        ->and($subscription['context']['skip_confirmation'])->toBeTrue()
        ->and($subscription['context']['source'])->toBe('lead-magnets')
        ->and($subscription['context']['meta']['consent'])->toBe([
            'method' => 'lead_magnet_confirmation',
            'list' => 'newsletter',
            'text' => DISCLOSURE,
            'source' => 'lead-magnets:vowel_guide',
            'requested_at' => '2026-10-04T18:00:00+00:00',
            'confirmed_at' => '2026-10-04T18:05:00+00:00',
        ]);
});

it('records the wording the reader was shown, not what the resource says by the time they confirm', function () {
    $resource = coupledResource();
    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');
    $token = tokenFromLastConfirmationMail();

    $resource->forceFill(['list_consent_text' => 'Eine spaetere Fassung.'])->save();

    $this->post(route('lead-magnets.confirm.store', ['token' => $token]))->assertOk();

    expect(FakeMarketingService::$subscriptions[0]['context']['meta']['consent']['text'])->toBe(DISCLOSURE);
});

it('confirms once: a second POST changes nothing and subscribes nobody twice', function () {
    $resource = coupledResource();
    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');
    $token = tokenFromLastConfirmationMail();

    $this->post(route('lead-magnets.confirm.store', ['token' => $token]))->assertOk();
    $mails = sentMailCount();

    // The token is spent on activation, so the second press finds nothing.
    $this->post(route('lead-magnets.confirm.store', ['token' => $token]))->assertNotFound();

    expect(sentMailCount())->toBe($mails)
        ->and(FakeMarketingService::$subscriptions)->toHaveCount(1);
});

it('leaves an uncoupled resource exactly as it was: GET confirms, the list asks for its own confirmation', function () {
    makeResource([
        'handle' => 'plain',
        'requires_confirmation' => true,
        'marketing_list' => 'newsletter',
    ]);

    app(LeadMagnetsManager::class)->request(
        Goldnead\LeadMagnets\Models\Resource::query()->sole(),
        'reader@example.com',
    );

    $this->get(route('lead-magnets.confirm', ['token' => tokenFromLastConfirmationMail()]))->assertOk();

    expect(Grant::query()->with('entitlement')->sole()->state())->toBe(EntitlementState::Active)
        ->and(Grant::query()->sole()->meta['list_consent'] ?? null)->toBeNull()
        ->and(FakeMarketingService::$subscriptions)->toHaveCount(1)
        ->and(FakeMarketingService::$subscriptions[0]['context'])->not->toHaveKey('skip_confirmation');
});

it('does not couple a resource that sends no confirmation at all', function () {
    coupledResource(['handle' => 'instant', 'requires_confirmation' => false]);

    app(LeadMagnetsManager::class)->request(
        Goldnead\LeadMagnets\Models\Resource::query()->sole(),
        'reader@example.com',
    );

    expect(FakeMarketingService::$subscriptions)->toHaveCount(1)
        ->and(FakeMarketingService::$subscriptions[0]['context'])->not->toHaveKey('skip_confirmation');
});

it('does not couple without a disclosure, because there is nothing the reader agreed to', function () {
    coupledResource(['list_consent_text' => '  ']);

    app(LeadMagnetsManager::class)->request(
        Goldnead\LeadMagnets\Models\Resource::query()->sole(),
        'reader@example.com',
    );

    $this->get(route('lead-magnets.confirm', ['token' => tokenFromLastConfirmationMail()]))->assertOk();

    expect(FakeMarketingService::$subscriptions)->toHaveCount(1)
        ->and(FakeMarketingService::$subscriptions[0]['context'])->not->toHaveKey('skip_confirmation');
});

it('lets marketing ask for itself when an editor activates a pending coupled grant', function () {
    $resource = coupledResource();
    $grant = app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');

    app(LeadMagnetsManager::class)->reinstate(Grant::query()->find($grant->id));

    expect(Grant::query()->with('entitlement')->sole()->state())->toBe(EntitlementState::Active)
        ->and(FakeMarketingService::$subscriptions)->toHaveCount(1)
        ->and(FakeMarketingService::$subscriptions[0]['context'])->not->toHaveKey('skip_confirmation');
});

it('shows the lapsed page on POST for a confirmation that ran out', function () {
    $resource = coupledResource();
    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');
    $token = tokenFromLastConfirmationMail();

    Carbon::setTestNow(now()->addHours(73));

    $this->post(route('lead-magnets.confirm.store', ['token' => $token]))
        ->assertOk()
        ->assertSee('data-state="lapsed"', false);

    expect(Grant::query()->with('entitlement')->sole()->state())->toBe(EntitlementState::Pending)
        ->and(FakeMarketingService::$subscriptions)->toBe([]);
});

it('hands the disclosure to an authored confirmation template', function () {
    FakeEmailTemplatesFacade::$templates['freebie-confirm'] = new FakeEmailTemplate(
        '<p>{{ list_consent_text }}</p><a href="{{ confirm_url }}">Ja</a>',
        'Bitte bestaetigen',
    );

    coupledResource(['confirmation_template' => 'freebie-confirm']);

    app(LeadMagnetsManager::class)->request(
        Goldnead\LeadMagnets\Models\Resource::query()->sole(),
        'reader@example.com',
    );

    expect(lastMailBody())->toContain(DISCLOSURE)
        ->and(lastMailBody())->toContain('Bitte bestaetigen');
});

it('uses the delivery template named on the resource before the configured one', function () {
    config()->set('lead-magnets.mail.delivery_template', 'everyone');

    FakeEmailTemplatesFacade::$templates['everyone'] = new FakeEmailTemplate('<p>Allgemein {{ download_url }}</p>');
    FakeEmailTemplatesFacade::$templates['vowel-welcome'] = new FakeEmailTemplate('<p>Dein Vowel Guide {{ download_url }}</p>', 'Dein Vowel Guide ist bereit');

    makeResource(['handle' => 'vowel', 'requires_confirmation' => false, 'delivery_template' => 'vowel-welcome']);
    makeResource(['handle' => 'other', 'requires_confirmation' => false, 'file_path' => 'other.txt']);

    app(LeadMagnetsManager::class)->request(Goldnead\LeadMagnets\Models\Resource::query()->where('handle', 'vowel')->sole(), 'a@example.com');
    expect(lastMailBody())->toContain('Dein Vowel Guide')->and(lastMailBody())->not->toContain('Allgemein');

    app(LeadMagnetsManager::class)->request(Goldnead\LeadMagnets\Models\Resource::query()->where('handle', 'other')->sole(), 'b@example.com');
    expect(lastMailBody())->toContain('Allgemein');
});

it('keeps the confirmation it recorded on the grant once the claim was won', function () {
    $resource = coupledResource();
    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');

    $this->post(route('lead-magnets.confirm.store', ['token' => tokenFromLastConfirmationMail()]))->assertOk();

    expect(Grant::query()->sole()->meta['list_consent']['confirmed_at'] ?? null)->not->toBeNull();
});

it('does not reuse an old confirmed consent when a lapsed grant is asked for again without a confirmation', function () {
    $resource = coupledResource();
    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');
    $this->post(route('lead-magnets.confirm.store', ['token' => tokenFromLastConfirmationMail()]))->assertOk();

    // The access window closed, and the editor has since switched the
    // confirmation off.
    Grant::query()->with('entitlement')->sole()->entitlement->forceFill(['expires_at' => now()->subDay()])->save();
    $resource->forceFill(['requires_confirmation' => false])->save();
    FakeMarketingService::$subscriptions = [];

    app(LeadMagnetsManager::class)->request($resource->fresh(), 'reader@example.com');

    expect(FakeMarketingService::$subscriptions)->toHaveCount(1)
        ->and(FakeMarketingService::$subscriptions[0]['context'])->not->toHaveKey('skip_confirmation')
        ->and(Grant::query()->sole()->meta['list_consent'] ?? null)->toBeNull();
});

it('leaves no consent behind when the press did not activate the grant', function () {
    $resource = coupledResource();
    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');
    $token = tokenFromLastConfirmationMail();

    // An activation that cannot happen: the entitlement row is gone.
    Entitlement::query()->delete();

    $this->post(route('lead-magnets.confirm.store', ['token' => $token]));

    expect(Grant::query()->sole()->meta['list_consent']['confirmed_at'] ?? null)->toBeNull()
        ->and(FakeMarketingService::$subscriptions)->toBe([]);
});

it('stops an already mailed link from confirming into the list once the resource is decoupled', function () {
    $resource = coupledResource();
    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');
    $token = tokenFromLastConfirmationMail();

    $resource->forceFill(['list_via_confirmation' => false])->save();

    // Back to the plain flow: the link confirms the file, the list asks itself.
    $this->get(route('lead-magnets.confirm', ['token' => $token]))->assertOk();

    expect(Grant::query()->with('entitlement')->sole()->state())->toBe(EntitlementState::Active)
        ->and(FakeMarketingService::$subscriptions)->toHaveCount(1)
        ->and(FakeMarketingService::$subscriptions[0]['context'])->not->toHaveKey('skip_confirmation');
});

it('keeps the record of a confirmed consent when the address asks again', function () {
    $resource = coupledResource();
    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');
    $this->post(route('lead-magnets.confirm.store', ['token' => tokenFromLastConfirmationMail()]))->assertOk();

    // Anybody can type the address into the form again.
    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com', ['list_consent' => ['forged' => true]]);

    expect(Grant::query()->sole()->meta['list_consent']['confirmed_at'] ?? null)->not->toBeNull()
        ->and(Grant::query()->sole()->meta['list_consent'])->not->toHaveKey('forged');
});

it('does not warn about a template that shows the disclosure with quotes in it', function () {
    Log::spy();

    FakeEmailTemplatesFacade::$templates['shows'] = new FakeEmailTemplate('<p>{{ list_consent_text }}</p><a href="{{ confirm_url }}">Ja</a>');

    coupledResource(['confirmation_template' => 'shows', 'list_consent_text' => 'Du bekommst den "Newsletter" & kannst dich abmelden.']);

    app(LeadMagnetsManager::class)->request(Resource::query()->sole(), 'reader@example.com');

    Log::shouldNotHaveReceived('warning');
});

it('names a confirmation template that leaves the disclosure out', function () {
    Log::spy();

    FakeEmailTemplatesFacade::$templates['silent'] = new FakeEmailTemplate('<a href="{{ confirm_url }}">Ja</a>');

    coupledResource(['confirmation_template' => 'silent']);

    app(LeadMagnetsManager::class)->request(Resource::query()->sole(), 'reader@example.com');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, '{{ list_consent_text }}'))
        ->once();
});

it('serves the confirm button through the web middleware, so the form carries a session and a CSRF token', function () {
    $route = app('router')->getRoutes()->getByName('lead-magnets.confirm.store');

    expect($route->gatherMiddleware())->toContain('web');
});
