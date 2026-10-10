<?php

use Goldnead\LeadMagnets\Integrations\SiblingBridges;
use Goldnead\LeadMagnets\LeadMagnetsManager;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Support\ReturnUrl;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeMailingListRepository;
use Goldnead\LeadMagnets\Tests\Fixtures\SiblingStubs;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/*
 * Where the confirmation link sends the reader.
 *
 * A caller can say "afterwards, go here" (a funnel does: the reader continues
 * on the next step). The address is a redirect target, so the only thing that
 * makes it safe is that it cannot come from a visitor: it has to be a link
 * this application signed, on this host, and the public form never reads it.
 */

beforeEach(function () {
    Route::get('/weiter/{step}', fn (string $step) => 'step '.$step)->name('test.weiter');
    Route::getRoutes()->refreshNameLookups();
});

function signedBack(string $step = 'danke'): string
{
    return URL::signedRoute('test.weiter', ['step' => $step]);
}

function requestWithReturn(mixed $returnUrl, string $email = 'reader@example.com', array $resource = []): Grant
{
    $resource = makeResource($resource);

    return app(LeadMagnetsManager::class)->request(
        $resource,
        $email,
        $returnUrl === null ? [] : ['return_url' => $returnUrl],
    );
}

it('sends the reader to the signed return URL after the confirmation', function () {
    $back = signedBack();
    requestWithReturn($back);

    $token = tokenFromLastConfirmationMail();
    $mails = sentMailCount();

    $this->get(route('lead-magnets.confirm', ['token' => $token]))
        ->assertRedirect($back)
        ->assertStatus(303);

    // The redirect does not replace the work: the grant is active and the
    // delivery mail went out exactly once.
    expect(Grant::query()->sole()->isActive())->toBeTrue()
        ->and(sentMailCount())->toBe($mails + 1);
});

it('shows its own page when no return URL was given', function () {
    requestWithReturn(null);

    $this->get(route('lead-magnets.confirm', ['token' => tokenFromLastConfirmationMail()]))
        ->assertOk()
        ->assertSee('data-state="active"', false);
});

it('drops a return URL that was not signed', function () {
    Log::spy();

    $grant = requestWithReturn('http://localhost/weiter/danke');

    expect($grant->meta)->not->toHaveKey('return_url');
    Log::shouldHaveReceived('warning')->once();

    $this->get(route('lead-magnets.confirm', ['token' => tokenFromLastConfirmationMail()]))
        ->assertOk();
});

it('drops a signed URL whose query string was edited', function () {
    $edited = str_replace('/weiter/danke', '/weiter/anderswo', signedBack());

    $grant = requestWithReturn($edited);

    expect($grant->meta)->not->toHaveKey('return_url');
});

it('drops a URL signed for another host', function () {
    URL::forceRootUrl('http://evil.example');
    $foreign = signedBack();
    URL::forceRootUrl(null);

    expect($foreign)->toStartWith('http://evil.example/');

    $grant = requestWithReturn($foreign);

    expect($grant->meta)->not->toHaveKey('return_url');

    $this->get(route('lead-magnets.confirm', ['token' => tokenFromLastConfirmationMail()]))
        ->assertOk();
});

it('refuses anything that is not a plain http(s) URL', function (mixed $value) {
    expect(ReturnUrl::accept($value))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'array' => [['http://localhost/x']],
    'javascript' => ['javascript:alert(1)'],
    'protocol-relative' => ['//evil.example/x'],
    'relative' => ['/weiter/danke'],
    'credentials' => ['http://user:pass@localhost/weiter/danke'],
    'too long' => ['http://localhost/'.str_repeat('a', 3000)],
]);

it('re-checks the URL when it is followed, so an edited grant row cannot redirect', function () {
    $grant = requestWithReturn(signedBack());

    $grant->forceFill(['meta' => array_merge($grant->meta, ['return_url' => 'https://evil.example/phish'])])->save();

    $this->get(route('lead-magnets.confirm', ['token' => tokenFromLastConfirmationMail()]))
        ->assertOk();
});

it('does not read a return URL from the public form', function () {
    makeResource();

    $this->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'warm_up',
        'return_url' => signedBack(),
        'meta' => ['return_url' => signedBack()],
    ]);

    expect(Grant::query()->sole()->meta ?? [])->not->toHaveKey('return_url');
});

it('lets a repeat request replace the return URL instead of inheriting the old one', function () {
    $resource = makeResource();
    $manager = app(LeadMagnetsManager::class);

    $manager->request($resource, 'reader@example.com', ['return_url' => signedBack('eins')]);
    expect(Grant::query()->sole()->meta['return_url'])->toContain('/weiter/eins');

    $manager->request($resource, 'reader@example.com', ['return_url' => signedBack('zwei')]);
    expect(Grant::query()->sole()->meta['return_url'])->toContain('/weiter/zwei');

    // A later plain request (the form on the site) must not send the reader
    // back into the flow of the earlier one.
    $manager->request($resource, 'reader@example.com');
    expect(Grant::query()->sole()->meta ?? [])->not->toHaveKey('return_url');
});

it('does not redirect when the confirmation link has lapsed', function () {
    requestWithReturn(signedBack());

    Grant::query()->update(['confirm_expires_at' => now()->subHour()]);

    $this->get(route('lead-magnets.confirm', ['token' => tokenFromLastConfirmationMail()]))
        ->assertOk()
        ->assertSee('data-state="lapsed"', false);
});

it('redirects from the consent button too, and not from the page that shows it', function () {
    SiblingStubs::bindAll();
    FakeMailingListRepository::$lists['newsletter'] = (object) ['handle' => 'newsletter'];
    app()->forgetInstance(SiblingBridges::class);
    app(SiblingBridges::class)->boot(app('events'));

    $back = signedBack();

    requestWithReturn($back, resource: [
        'marketing_list' => 'newsletter',
        'list_via_confirmation' => true,
        'list_consent_text' => 'Mit der Anforderung meldest du dich zum Newsletter an.',
    ]);

    $token = tokenFromLastConfirmationMail();

    // Opening the link is not consent: the page with the button, no redirect,
    // nothing confirmed.
    $this->get(route('lead-magnets.confirm', ['token' => $token]))->assertOk();
    expect(Grant::query()->sole()->isPending())->toBeTrue();

    $this->post(route('lead-magnets.confirm.store', ['token' => $token]))
        ->assertRedirect($back);

    expect(Grant::query()->sole()->isActive())->toBeTrue();
});

it('sends an instant grant nowhere: nothing to confirm, so the caller continues itself', function () {
    $grant = requestWithReturn(signedBack(), resource: ['requires_confirmation' => false]);

    expect($grant->isActive())->toBeTrue()
        // Kept on the grant, harmless: there is no confirmation link to follow.
        ->and(sentMailCount())->toBe(1);
});
