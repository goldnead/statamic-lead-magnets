<?php

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\LeadMagnets\Integrations\Map\LeadMagnetMapObjects;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeEmailTemplate;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeEmailTemplatesFacade;
use Goldnead\LeadMagnets\Tests\Fixtures\SiblingStubs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * The two mails of a lead magnet in the dry run of the map: the confirmation and the delivery. The map
 * asks the provider for the mail an effect names (`mailPreview`); what it hands back is rendered like the
 * real mail (the template the editor wrote, else the addon's own view) and sends nothing, stores nothing
 * and signs no link: the address in it is `#`.
 *
 * Needs the simulation classes of statamic-brand-context; skipped without them.
 */

beforeEach(function () {
    if (! class_exists(Effect::class)) {
        $this->markTestSkipped('needs the simulation classes of statamic-brand-context (Effect, SimEvent, SimPerson)');
    }

    SiblingStubs::bindAll();
});

function mpResource(array $attributes = []): Resource
{
    return makeResource(array_merge([
        'handle' => 'cvt-uberblick',
        'title' => 'CVT Überblick',
        'requires_confirmation' => true,
    ], $attributes));
}

function mpPreview(string $kind, ?SimPerson $person = null): ?array
{
    return app(LeadMagnetMapObjects::class)->mailPreview('cvt-uberblick', ['kind' => $kind], $person ?? SimPerson::known(['contact.email' => 'lena@example.com']));
}

it('names the mail on the effect, so the screen can ask for it', function () {
    mpResource();

    $effects = app(LeadMagnetMapObjects::class)->react('cvt-uberblick', new SimEvent(SimEvent::REQUESTED), SimPerson::unknown(), CarbonImmutable::now());

    $mails = [];
    $walk = function (array $list) use (&$walk, &$mails) {
        foreach ($list as $e) {
            if ($e->kind === Effect::MAIL) {
                $mails[] = $e->meta['kind'] ?? null;
            }
            $walk($e->children);
        }
    };
    $walk($effects);

    expect($mails)->toBe(['confirmation', 'delivery']);
});

it('renders the confirmation mail from the template the editor wrote, for the person, with an inert link', function () {
    mpResource(['confirmation_template' => 'bestaetigung']);
    FakeEmailTemplatesFacade::$templates['bestaetigung'] = new FakeEmailTemplate('<p>Hallo {{ email }}, {{ resource_title }}</p><a href="{{ confirm_url }}">Bestätigen</a>', 'Bitte bestätigen');

    $mail = mpPreview('confirmation');

    expect($mail['html'])->toContain('Hallo lena@example.com, CVT Überblick')
        ->and($mail['html'])->toContain('href="#"')
        ->and($mail['subject'])->toBe('Bitte bestätigen');
});

it('renders the delivery mail from its template', function () {
    mpResource(['delivery_template' => 'lieferung']);
    FakeEmailTemplatesFacade::$templates['lieferung'] = new FakeEmailTemplate('<p>{{ resource_title }}</p><a href="{{ download_url }}">Laden</a>', 'Dein Download');

    $mail = mpPreview('delivery');

    expect($mail['html'])->toContain('CVT Überblick')->toContain('href="#"')
        ->and($mail['subject'])->toBe('Dein Download');
});

it('renders the addons own mail when the editor wrote no template', function () {
    $resource = mpResource();

    $confirmation = mpPreview('confirmation');
    $delivery = mpPreview('delivery');

    expect($confirmation['subject'])->toBe(__('lead-magnets::mail.confirmation_subject', ['title' => $resource->title]))
        ->and($confirmation['html'])->toContain(__('lead-magnets::mail.confirmation_greeting'))
        ->and($delivery['subject'])->toBe(__('lead-magnets::mail.delivery_subject', ['title' => $resource->title]))
        ->and($delivery['html'])->toContain(__('lead-magnets::mail.delivery_greeting'));
});

it('has no mail for a kind it does not know, a magnet that is gone, or a template with a hole it cannot fill', function () {
    mpResource(['confirmation_template' => 'kaputt']);
    FakeEmailTemplatesFacade::$templates['kaputt'] = new FakeEmailTemplate('Hi {{ gibt_es_nicht }}');

    $provider = app(LeadMagnetMapObjects::class);

    expect(mpPreview('confirmation'))->toBeNull()
        ->and(mpPreview('newsletter'))->toBeNull()
        ->and($provider->mailPreview('gibt-es-nicht', ['kind' => 'delivery'], SimPerson::unknown()))->toBeNull()
        ->and($provider->mailPreview('cvt-uberblick', [], SimPerson::unknown()))->toBeNull();
});

it('sends nothing, writes nothing and creates no grant', function () {
    mpResource();
    Mail::fake();
    Queue::fake();
    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (! preg_match('/^\s*(select|pragma)\b/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });

    mpPreview('confirmation');
    mpPreview('delivery', SimPerson::unknown());

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
    expect($writes)->toBe([])
        ->and(Grant::query()->count())->toBe(0);
});
