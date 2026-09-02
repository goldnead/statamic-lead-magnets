<?php

use Goldnead\LeadMagnets\Events\ResourceConfirmed;
use Goldnead\LeadMagnets\Integrations\ActivityBridge;
use Goldnead\LeadMagnets\Integrations\EmailTemplatesBridge;
use Goldnead\LeadMagnets\Integrations\LeadhubBridge;
use Goldnead\LeadMagnets\Integrations\SiblingBridges;
use Goldnead\LeadMagnets\Integrations\SuppressionBridge;
use Goldnead\LeadMagnets\LeadMagnetsManager;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Services\DeliveryService;
use Goldnead\LeadMagnets\Services\DownloadLink;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeActivityFacade;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeEmailTemplate;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeEmailTemplatesFacade;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeLeadHubFacade;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeMailingListRepository;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeMarketingService;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeSuppressionGate;
use Goldnead\LeadMagnets\Tests\Fixtures\SiblingStubs;

/*
 * The optional bridges, against stand-ins.
 *
 * What is under test here is this addon's side of each bridge: the guard, the
 * ordering and what happens when the sibling misbehaves. None of the siblings
 * is installed, and none is being tested.
 */

beforeEach(function () {
    SiblingStubs::bindAll();

    // The bridges were wired at boot, when none of them was available. Rebuild
    // the registrar so it sees the stand-ins and registers its listeners.
    app()->forgetInstance(SiblingBridges::class);
    app(SiblingBridges::class)->boot(app('events'));
});

it('registers its listeners when at least one sibling is present', function () {
    expect(app(SiblingBridges::class)->booted())->toBeTrue();
});

it('probes the object behind a facade, not the facade class', function () {
    // The whole reason Bridge::rootHas() exists. If this ever goes back to
    // method_exists() on the facade, the assertion below flips and the bridges
    // start doing nothing at all — silently, which is how it happened last time.
    expect(method_exists(FakeLeadHubFacade::class, 'findByEmail'))->toBeFalse()
        ->and(app(LeadhubBridge::class)->available())->toBeTrue();

    $resource = makeResource(['requires_confirmation' => false, 'tags' => ['lead-magnet']]);

    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');

    expect(FakeLeadHubFacade::$contacts)->toHaveKey('reader@example.com');
});

it('creates the contact once and writes the resource tags onto it', function () {
    $resource = makeResource([
        'requires_confirmation' => false,
        'tags' => ['lead-magnet', 'warm-up'],
    ]);

    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');

    $contactId = FakeLeadHubFacade::$contacts['reader@example.com']['id'];

    expect(FakeLeadHubFacade::$tags[$contactId])->toBe(['lead-magnet', 'warm-up'])
        ->and(Grant::query()->sole()->contact_id)->toBe($contactId);
});

it('does not create a second contact for an address leadhub already knows', function () {
    FakeLeadHubFacade::$contacts['reader@example.com'] = ['id' => 'existing-1', 'email' => 'reader@example.com'];

    $resource = makeResource(['requires_confirmation' => false, 'tags' => ['x']]);

    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');

    expect(FakeLeadHubFacade::$contacts)->toHaveCount(1)
        ->and(Grant::query()->sole()->contact_id)->toBe('existing-1');
});

it('delivers the file even when leadhub throws', function () {
    FakeLeadHubFacade::$throwOnAddTag = true;

    $resource = makeResource(['requires_confirmation' => false, 'tags' => ['boom']]);

    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');

    // Delivering the resource is the promise; tagging a contact is a courtesy.
    // A broken sibling may not cost the visitor their download.
    expect(Grant::query()->sole()->delivered_at)->not->toBeNull();
});

it('holds the send for a suppressed address and says why on the grant', function () {
    FakeSuppressionGate::$suppressed = ['bounced@example.com'];

    $resource = makeResource(['requires_confirmation' => false]);

    $before = sentMailCount();

    app(LeadMagnetsManager::class)->request($resource, 'bounced@example.com');

    $grant = Grant::query()->sole();

    expect(sentMailCount())->toBe($before)
        ->and($grant->delivered_at)->toBeNull()
        // Active but never delivered is a mystery; the reason on the record
        // answers the support mail by itself.
        ->and($grant->meta['last_hold'])->toBe('delivery_suppressed');
});

it('holds the send when the suppression list cannot answer', function () {
    FakeSuppressionGate::$throws = true;

    expect(app(SuppressionBridge::class)->blocks('anyone@example.com'))->toBeTrue();

    // Closed, not open: a bounce list that errors is not permission to mail a
    // complainant. The opposite direction — no addon installed at all — is
    // covered in NoSiblingsInstalledTest, and fails open on purpose.
});

it('uses an editor-authored mail body when email-templates has one', function () {
    FakeEmailTemplatesFacade::$templates['lead-magnet-delivery'] = new FakeEmailTemplate(
        '<p>Here you go, {{ resource_title }}: {{ download_url }}</p>',
        'Your {{ resource_title }}',
    );

    $resource = makeResource(['requires_confirmation' => false]);

    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');

    $mail = quoted_printable_decode(
        app('mailer')->getSymfonyTransport()->messages()[0]->getMessage()->toString()
    );

    expect($mail)->toContain('Here you go, Warm-up routine')
        ->and($mail)->toContain('Your Warm-up routine');
});

it('escapes a supplied value in the body but leaves the link and the subject alone', function () {
    // The mail this renders goes to an address nobody has confirmed yet, and
    // the values in it came out of the request form. Same class of defect as
    // statamic-payments/AbandonedReminder (02.09.2026): a value carrying markup
    // arrived as markup.
    FakeEmailTemplatesFacade::$templates['lead-magnet-delivery'] = new FakeEmailTemplate(
        '<p>Hallo {{ resource_title }}: <a href="{{ download_url }}">Download</a></p>',
        'Dein {{ resource_title }}',
    );

    $rendered = app(EmailTemplatesBridge::class)->render('lead-magnet-delivery', [
        'resource_title' => '<script>alert(1)</script> Müller & Söhne',
        'download_url' => 'https://example.com/d?id=7&sig=abc',
    ]);

    expect($rendered['html'])
        // Escaped exactly once: the markup is text, the ampersand is not doubled.
        ->toContain('Hallo &lt;script&gt;alert(1)&lt;/script&gt; Müller &amp; Söhne')
        ->not->toContain('<script>')
        ->not->toContain('&amp;amp;')
        // The link is named in RAW_VARIABLES and survives its query string.
        ->toContain('href="https://example.com/d?id=7&sig=abc"');

    // The subject is not HTML.
    expect($rendered['subject'])->toBe('Dein <script>alert(1)</script> Müller & Söhne');
});

it('falls back to its own view when the template is missing or empty', function () {
    FakeEmailTemplatesFacade::$templates['lead-magnet-delivery'] = new FakeEmailTemplate('');

    expect(app(EmailTemplatesBridge::class)->render('lead-magnet-delivery', []))->toBeNull()
        ->and(app(EmailTemplatesBridge::class)->render('nothing-here', []))->toBeNull();

    $resource = makeResource(['requires_confirmation' => false]);

    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');

    expect(downloadUrlFromLastDeliveryMail())->not->toBeNull();
});

it('records all four events on the activity ledger', function () {
    $resource = makeResource(['requires_confirmation' => false]);

    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');

    $grant = Grant::query()->with('resource')->sole();

    $this->get(app(DownloadLink::class)->for($grant))->assertOk();

    $types = array_column(FakeActivityFacade::$records, 0);

    expect($types)->toContain('lead-magnets.resource.requested')
        ->toContain('lead-magnets.resource.confirmed')
        ->toContain('lead-magnets.resource.delivered')
        ->toContain('lead-magnets.resource.downloaded');
});

it('keeps the confirmation secret out of the event payload', function () {
    makeResource();

    $captured = null;

    app('events')->listen(ResourceConfirmed::class, function ($event) use (&$captured) {
        $captured = $event->payload();
    });

    $this->post(route('lead-magnets.request'), ['email' => 'reader@example.com', 'resource' => 'warm_up']);

    $token = tokenFromLastConfirmationMail();

    $this->get(route('lead-magnets.confirm', ['token' => $token]));

    // The payload travels to webhook endpoints and activity ledgers. A
    // confirmation secret in an outbound webhook is a confirmation anyone
    // can replay.
    expect(json_encode($captured))->not->toContain($token)
        ->and($captured)->not->toHaveKey('token_hash');
});

it('subscribes a confirmed address only when the resource names a list', function () {
    FakeMailingListRepository::$lists['newsletter'] = (object) ['handle' => 'newsletter'];

    $withList = makeResource([
        'handle' => 'with_list',
        'requires_confirmation' => false,
        'marketing_list' => 'newsletter',
    ]);

    $withoutList = makeResource(['handle' => 'without_list', 'requires_confirmation' => false]);

    app(LeadMagnetsManager::class)->request($withList, 'subscriber@example.com');
    app(LeadMagnetsManager::class)->request($withoutList, 'passerby@example.com');

    expect(FakeMarketingService::$subscriptions)->toHaveCount(1)
        ->and(FakeMarketingService::$subscriptions[0]['email'])->toBe('subscriber@example.com')
        ->and(FakeMarketingService::$subscriptions[0]['context']['source'])->toBe('lead-magnets');
});

it('does not subscribe to a list marketing does not have', function () {
    $resource = makeResource(['requires_confirmation' => false, 'marketing_list' => 'gone']);

    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');

    expect(FakeMarketingService::$subscriptions)->toBe([])
        ->and(Grant::query()->sole()->delivered_at)->not->toBeNull();
});

it('turns a bridge off through config even while its addon is present', function () {
    config()->set('lead-magnets.integrations.leadhub', false);
    config()->set('lead-magnets.integrations.activity', false);

    expect(app(LeadhubBridge::class)->available())->toBeFalse()
        ->and(app(ActivityBridge::class)->available())->toBeFalse();

    $resource = makeResource(['requires_confirmation' => false, 'tags' => ['x']]);

    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');

    expect(FakeLeadHubFacade::$contacts)->toBe([])
        ->and(FakeActivityFacade::$records)->toBe([])
        ->and(Grant::query()->sole()->delivered_at)->not->toBeNull();
});

it('never re-registers its listeners when boot runs again', function () {
    // Statamic fires its booted callbacks twice, and the provider queues this
    // boot from inside one of them on purpose. The guard is what keeps the
    // second pass from doubling every event.
    $registrar = app(SiblingBridges::class);

    $registrar->boot(app('events'));
    $registrar->boot(app('events'));

    $resource = makeResource(['requires_confirmation' => false]);

    app(LeadMagnetsManager::class)->request($resource, 'reader@example.com');

    $confirmed = array_filter(
        FakeActivityFacade::$records,
        fn ($record) => $record[0] === 'lead-magnets.resource.confirmed'
    );

    expect($confirmed)->toHaveCount(1);
});

it('re-sends a delivery for an address that has since been released', function () {
    FakeSuppressionGate::$suppressed = ['bounced@example.com'];

    $resource = makeResource(['requires_confirmation' => false]);

    app(LeadMagnetsManager::class)->request($resource, 'bounced@example.com');

    $grant = Grant::query()->with('resource')->sole();

    expect($grant->delivered_at)->toBeNull();

    FakeSuppressionGate::$suppressed = [];

    expect(app(DeliveryService::class)->deliver($grant))->toBeTrue()
        ->and($grant->fresh()->delivered_at)->not->toBeNull();
});
