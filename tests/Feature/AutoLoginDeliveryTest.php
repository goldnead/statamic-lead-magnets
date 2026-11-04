<?php

use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\IdentityContracts\AutoLogin\AutoLogin;
use Goldnead\LeadMagnets\Contracts\AccessSubjectResolver;
use Goldnead\LeadMagnets\Integrations\AutomationsBridge;
use Goldnead\LeadMagnets\LeadMagnetsManager;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Services\AccessStartLink;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeEmailTemplate;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeEmailTemplatesFacade;
use Goldnead\LeadMagnets\Tests\Fixtures\SiblingStubs;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * The delivery mail of an access resource carries a link to a page behind the
 * login. For a reader with an account it logs them in.
 */
class AutoLoginDeliveryUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

class AutoLoginDeliveryResolver implements AccessSubjectResolver
{
    public function subjectFor(string $email, Grant $grant): mixed
    {
        return new SubjectReference('user', '1');
    }

    public function accessUrl(mixed $subject, Resource $resource, Grant $grant): ?string
    {
        return 'http://localhost'.($resource->access_path ?? '/account');
    }
}

beforeEach(function (): void {
    if (! class_exists(AutoLogin::class)) {
        $this->markTestSkipped('goldnead/statamic-identity-contracts 1.3 or later is not installed.');
    }

    SiblingStubs::bindAll();
    app()->singleton(AccessSubjectResolver::class, fn () => new AutoLoginDeliveryResolver);
    app()->singleton(AutomationsBridge::class, fn () => new class extends AutomationsBridge
    {
        public function isLive(string $handle): ?bool
        {
            return false;
        }

        public function takesOver(string $handle, string $email): bool
        {
            return false;
        }
    });

    config([
        'app.url' => 'http://localhost',
        'auth.providers.users.model' => AutoLoginDeliveryUser::class,
        'identity-contracts.autologin.enabled' => true,
        'identity-contracts.autologin.paths' => ['/account/'],
    ]);
    app('router')->getRoutes()->refreshNameLookups();

    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('role')->nullable();
            $table->boolean('super')->default(false);
            $table->rememberToken();
        });
    }

    $this->reader = AutoLoginDeliveryUser::create(['email' => 'reader@example.com', 'password' => Hash::make('x'), 'role' => 'student']);

    makeResource([
        'handle' => 'minikurs',
        'title' => 'Minikurs',
        'delivery_type' => Resource::TYPE_ACCESS,
        'file_path' => null,
        'access_product' => 'minikurs-kurs',
        'access_path' => '/account/courses/minikurs',
        'delivery_series' => null,
        'delivery_template' => 'd',
    ]);
    FakeEmailTemplatesFacade::$templates['d'] = new FakeEmailTemplate('<p><a href="{{ access_url }}">Los</a> <a href="http://localhost/account/courses/minikurs/lessons/tag-1">Lektion</a></p>', 'Dein Zugang');
});

function autoLoginDeliveryMail(): string
{
    $all = '';
    foreach (app('mailer')->getSymfonyTransport()->messages() as $message) {
        $all .= html_entity_decode(quoted_printable_decode($message->getMessage()->toString()));
    }

    return $all;
}

function autoLoginConfirmedGrant(string $email): Grant
{
    $manager = app(LeadMagnetsManager::class);
    $manager->request(Resource::query()->where('handle', 'minikurs')->firstOrFail(), $email);

    return $manager->confirm(tokenFromLastConfirmationMail())->refresh();
}

it('turns the access link of the delivery mail into a login link for a reader with an account', function (): void {
    autoLoginConfirmedGrant('reader@example.com');

    $mail = autoLoginDeliveryMail();

    expect($mail)->toContain('http://localhost/!/autologin?')
        ->and($mail)->not->toContain('href="http://localhost/account/courses/minikurs"')
        ->and($mail)->not->toContain('href="http://localhost/account/courses/minikurs/lessons/tag-1"');

    preg_match_all('~http://localhost/!/autologin\?[^"\s<]+~', $mail, $m);
    expect($m[0])->toHaveCount(2);

    $this->get($m[0][0])->assertRedirect('/account/courses/minikurs');
    expect(Auth::id())->toBe($this->reader->id);
});

it('leaves the delivery mail alone for a reader without an account', function (): void {
    AutoLoginDeliveryUser::query()->delete();

    autoLoginConfirmedGrant('stranger@example.com');

    expect(autoLoginDeliveryMail())->not->toContain('autologin')
        ->and(autoLoginDeliveryMail())->toContain('http://localhost/account/courses/minikurs');
});

it('still sends the delivery mail with its plain links when the login link cannot be built', function (): void {
    config(['auth.providers.users.model' => 'App\\DoesNotExist']);

    autoLoginConfirmedGrant('reader@example.com');

    expect(autoLoginDeliveryMail())->not->toContain('autologin')
        ->and(autoLoginDeliveryMail())->toContain('http://localhost/account/courses/minikurs');
});

it('leaves the delivery mail alone when auto login is off', function (): void {
    config(['identity-contracts.autologin.enabled' => false]);

    autoLoginConfirmedGrant('reader@example.com');

    expect(autoLoginDeliveryMail())->not->toContain('autologin');
});

/**
 * The confirmed page of an access resource: a button that opens the start of
 * the access with the reader signed in.
 */
function autoLoginConfirmedPage(string $email): array
{
    $manager = app(LeadMagnetsManager::class);
    $manager->request(Resource::query()->where('handle', 'minikurs')->firstOrFail(), $email);
    $token = tokenFromLastConfirmationMail();

    // The link in the mail is the confirmation: this GET is the click.
    $page = test()->get(route('lead-magnets.confirm', ['token' => $token]));

    return [Grant::query()->where('email', $email)->firstOrFail(), $token, $page];
}

function autoLoginStartHref(string $html): ?string
{
    return preg_match('~<a href="([^"]+)" data-start~', html_entity_decode($html), $m) === 1 ? $m[1] : null;
}

it('offers a login link to the start of the access on the confirmed page', function (): void {
    [, , $page] = autoLoginConfirmedPage('reader@example.com');

    $page->assertOk()->assertSee('data-start', false);
    $href = autoLoginStartHref($page->getContent());

    expect($href)->toStartWith('http://localhost/!/autologin?');

    $this->get($href)->assertRedirect('/account/courses/minikurs');
    expect(Auth::id())->toBe($this->reader->id);
});

it('lets the host name the start with startUrl() on its resolver', function (): void {
    app()->singleton(AccessSubjectResolver::class, fn () => new class extends AutoLoginDeliveryResolver
    {
        public function startUrl(mixed $subject, Resource $resource, Grant $grant): ?string
        {
            return 'http://localhost/account/courses/minikurs/lessons/tag-1';
        }
    });

    [, , $page] = autoLoginConfirmedPage('reader@example.com');

    $this->get(autoLoginStartHref($page->getContent()))->assertRedirect('/account/courses/minikurs/lessons/tag-1');
});

it('offers no button for an address without an account', function (): void {
    AutoLoginDeliveryUser::query()->delete();

    [, , $page] = autoLoginConfirmedPage('stranger@example.com');

    $page->assertOk()->assertDontSee('data-start', false)->assertDontSee('autologin');
});

it('offers no login for a confirmation older than the login links last', function (): void {
    [$grant] = autoLoginConfirmedPage('reader@example.com');
    $links = app(AccessStartLink::class);

    expect($links->for($grant->refresh()))->toStartWith('http://localhost/!/autologin?');

    $grant->entitlement->forceFill(['starts_at' => now()->subDays(15)])->save();

    expect($links->for($grant->refresh()))->toBeNull();
});

it('offers no button for a grant that is still pending', function (): void {
    $manager = app(LeadMagnetsManager::class);
    $manager->request(Resource::query()->where('handle', 'minikurs')->firstOrFail(), 'reader@example.com');
    $grant = Grant::query()->where('email', 'reader@example.com')->firstOrFail();

    expect(app(AccessStartLink::class)->for($grant))->toBeNull();
});

it('offers no button when this switch is off', function (): void {
    config(['lead-magnets.confirmed_start_link' => false]);

    [, , $page] = autoLoginConfirmedPage('reader@example.com');

    $page->assertOk()->assertDontSee('data-start', false)->assertDontSee('autologin');
});

it('offers no button when the identity login is off', function (): void {
    config(['identity-contracts.autologin.enabled' => false]);

    [, , $page] = autoLoginConfirmedPage('reader@example.com');

    $page->assertOk()->assertDontSee('data-start', false)->assertDontSee('autologin');
});
