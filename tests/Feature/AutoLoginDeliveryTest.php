<?php

use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\IdentityContracts\AutoLogin\AutoLogin;
use Goldnead\LeadMagnets\Contracts\AccessSubjectResolver;
use Goldnead\LeadMagnets\Integrations\AutomationsBridge;
use Goldnead\LeadMagnets\LeadMagnetsManager;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
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

it('leaves the delivery mail alone when auto login is off', function (): void {
    config(['identity-contracts.autologin.enabled' => false]);

    autoLoginConfirmedGrant('reader@example.com');

    expect(autoLoginDeliveryMail())->not->toContain('autologin');
});
