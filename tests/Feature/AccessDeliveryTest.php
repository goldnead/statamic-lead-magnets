<?php

use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\LeadMagnets\Contracts\AccessSubjectResolver;
use Goldnead\LeadMagnets\Events\DeliveryVariables;
use Goldnead\LeadMagnets\Integrations\AutomationsBridge;
use Goldnead\LeadMagnets\LeadMagnetsManager;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeEmailTemplate;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeEmailTemplatesFacade;
use Goldnead\LeadMagnets\Tests\Fixtures\SiblingStubs;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

/**
 * A host that maps every address to the subject `user:<n>` and signs it in at
 * `/in/<n>`. Stands for the application's account model.
 */
class AccessTestResolver implements AccessSubjectResolver
{
    /** @var array<string, int> */
    public static array $users = [];

    public static int $calls = 0;

    public static bool $throws = false;

    public function subjectFor(string $email, Grant $grant): mixed
    {
        self::$calls++;

        if (self::$throws) {
            throw new RuntimeException('no account for you');
        }

        $id = self::$users[$email] ??= count(self::$users) + 1;

        return new SubjectReference('user', (string) $id);
    }

    public function accessUrl(mixed $subject, Resource $resource, Grant $grant): ?string
    {
        return 'https://example.test/in/'.$subject->id.($resource->access_path ?? '');
    }
}

class StubSeries extends AutomationsBridge
{
    public static ?bool $live = true;

    /** @var list<string> */
    public static array $ran = [];

    public function isLive(string $handle): ?bool
    {
        return self::$live;
    }

    public function takesOver(string $handle, string $email): bool
    {
        return self::$live === true && ! in_array($email, self::$ran, true);
    }
}

beforeEach(function (): void {
    AccessTestResolver::$users = [];
    AccessTestResolver::$calls = 0;
    AccessTestResolver::$throws = false;
    StubSeries::$live = true;
    StubSeries::$ran = [];

    SiblingStubs::bindAll();
    app()->singleton(AccessSubjectResolver::class, fn () => new AccessTestResolver);
    app()->singleton(AutomationsBridge::class, fn () => new StubSeries);
});

function makeAccessResource(array $attributes = []): Resource
{
    return makeResource(array_merge([
        'handle' => 'minikurs',
        'title' => 'Minikurs',
        'delivery_type' => Resource::TYPE_ACCESS,
        'file_path' => null,
        'access_product' => 'minikurs-kurs',
        'access_path' => '/account/courses/minikurs',
        'delivery_series' => null,
    ], $attributes));
}

function requestAndConfirm(string $handle, string $email): Grant
{
    $manager = app(LeadMagnetsManager::class);
    $manager->request(Resource::query()->where('handle', $handle)->firstOrFail(), $email);

    return $manager->confirm(tokenFromLastConfirmationMail())->refresh();
}

it('grants the product on the reader account after the confirmation and mails the sign-in link', function (): void {
    makeAccessResource();

    $grant = requestAndConfirm('minikurs', 'reader@example.com');

    $access = Entitlement::query()->where('product_slug', 'minikurs-kurs')->sole();

    expect($access->subject_type)->toBe('user')
        ->and($access->subject_id)->toBe('1')
        ->and($access->source)->toBe('lead_magnet_access')
        ->and($access->source_ref)->toBe('minikurs')
        ->and($access->status)->toBe('active');

    expect($grant->delivered_at)->not->toBeNull()
        ->and($grant->meta['delivery'])->toBe('mail');

    // Confirmation mail plus delivery mail, and the delivery mail carries the link.
    expect(sentMailCount())->toBe(2);
    $body = quoted_printable_decode(app('mailer')->getSymfonyTransport()->messages()->last()->getMessage()->toString());
    expect($body)->toContain('https://example.test/in/1/account/courses/minikurs');
});

it('grants no account access before the address is confirmed', function (): void {
    makeAccessResource();

    app(LeadMagnetsManager::class)->request(Resource::query()->firstOrFail(), 'reader@example.com');

    expect(Entitlement::query()->where('product_slug', 'minikurs-kurs')->count())->toBe(0)
        ->and(AccessTestResolver::$calls)->toBe(0);
});

it('lets a series replace the delivery mail for a first-time reader, and still grants the access', function (): void {
    makeAccessResource(['delivery_series' => 'minikurs-serie']);

    $grant = requestAndConfirm('minikurs', 'reader@example.com');

    expect(Entitlement::query()->where('product_slug', 'minikurs-kurs')->count())->toBe(1)
        ->and($grant->meta['delivery'])->toBe('series')
        ->and($grant->delivered_at)->not->toBeNull()
        // The confirmation mail only: the series sends the first mail.
        ->and(sentMailCount())->toBe(1);
});

it('mails the sign-in link when the reader had the series before', function (): void {
    makeAccessResource(['delivery_series' => 'minikurs-serie']);
    StubSeries::$ran = ['reader@example.com'];

    $grant = requestAndConfirm('minikurs', 'reader@example.com');

    expect($grant->meta['delivery'])->toBe('mail')
        ->and(sentMailCount())->toBe(2);
});

it('mails the sign-in link when the series is switched off or missing', function (): void {
    makeAccessResource(['delivery_series' => 'minikurs-serie']);
    StubSeries::$live = false;

    $grant = requestAndConfirm('minikurs', 'reader@example.com');

    expect($grant->meta['delivery'])->toBe('mail')
        ->and(sentMailCount())->toBe(2);
});

it('holds the delivery with a reason when the host binds no resolver', function (): void {
    makeAccessResource();
    app()->forgetInstance(AccessSubjectResolver::class);
    app()->offsetUnset(AccessSubjectResolver::class);

    $grant = requestAndConfirm('minikurs', 'reader@example.com');

    expect(Entitlement::query()->where('product_slug', 'minikurs-kurs')->count())->toBe(0)
        ->and($grant->meta['last_hold'])->toBe('access_resolver_missing')
        ->and($grant->delivered_at)->toBeNull();
});

it('holds the delivery with a reason when the resolver throws', function (): void {
    makeAccessResource();
    AccessTestResolver::$throws = true;

    $grant = requestAndConfirm('minikurs', 'reader@example.com');

    expect(Entitlement::query()->where('product_slug', 'minikurs-kurs')->count())->toBe(0)
        ->and($grant->meta['last_hold'])->toBe('access_grant_failed')
        ->and($grant->isActive())->toBeTrue();
});

it('holds the delivery with a reason when the resource names no product', function (): void {
    makeAccessResource(['access_product' => null]);

    $grant = requestAndConfirm('minikurs', 'reader@example.com');

    expect($grant->meta['last_hold'])->toBe('access_product_missing')
        ->and(Entitlement::query()->where('source', 'lead_magnet_access')->count())->toBe(0);
});

it('writes one access row however often the reader asks again', function (): void {
    makeAccessResource();

    requestAndConfirm('minikurs', 'reader@example.com');
    app(LeadMagnetsManager::class)->request(Resource::query()->firstOrFail(), 'reader@example.com');

    expect(Entitlement::query()->where('product_slug', 'minikurs-kurs')->count())->toBe(1);
});

it('does not serve a signed download link of an access resource', function (): void {
    makeAccessResource();
    $grant = requestAndConfirm('minikurs', 'reader@example.com');

    $url = URL::temporarySignedRoute('lead-magnets.download', now()->addHour(), ['grant' => $grant->id]);

    $this->get($url)->assertNotFound();
});

it('lets a listener add a placeholder to both mails', function (): void {
    makeAccessResource(['confirmation_template' => 'c', 'delivery_template' => 'd']);
    FakeEmailTemplatesFacade::$templates['c'] = new FakeEmailTemplate('Hi {{ extra }} {{ confirm_url }}');
    FakeEmailTemplatesFacade::$templates['d'] = new FakeEmailTemplate('Go {{ extra }} {{ access_url }}');

    Event::listen(DeliveryVariables::class, function (DeliveryVariables $event): void {
        $event->variables['extra'] = 'EXTRA-'.$event->kind;
    });

    requestAndConfirm('minikurs', 'reader@example.com');

    $messages = app('mailer')->getSymfonyTransport()->messages();
    $all = '';
    foreach ($messages as $message) {
        $all .= quoted_printable_decode($message->getMessage()->toString());
    }

    expect($all)->toContain('EXTRA-confirmation')->toContain('EXTRA-delivery');
});
