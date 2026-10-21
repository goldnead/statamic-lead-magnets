<?php

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\LeadMagnets\Integrations\AutomationsBridge;
use Goldnead\LeadMagnets\Integrations\Map\LeadMagnetMapObjects;
use Goldnead\LeadMagnets\Models\Resource;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\User;

/*
 * The dry run of a lead magnet: which events start it (paths) and what it would do
 * with an event (react). Nothing is sent, queued, written or granted, and the
 * test holds the line on that.
 *
 * Needs the simulation classes of statamic-brand-context; skipped without them.
 */

beforeEach(function () {
    if (! class_exists(Effect::class)) {
        $this->markTestSkipped('needs the simulation classes of statamic-brand-context (Effect, SimEvent, SimPerson)');
    }
});

/** A resource for the dry run; the fields the dry run reads are the ones given here. */
function simResource(string $handle, string $type, bool $confirm, bool $published = true): Resource
{
    return makeResource([
        'handle' => $handle,
        'title' => 'Atemübung',
        'delivery_type' => $type,
        'file_path' => $type === Resource::TYPE_FILE ? $handle.'.txt' : null,
        'link_url' => $type === Resource::TYPE_LINK ? 'https://example.test/'.$handle : null,
        'access_product' => $type === Resource::TYPE_ACCESS ? 'kurs-'.$handle : null,
        'requires_confirmation' => $confirm,
        'published' => $published,
    ]);
}

function simAt(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-21 10:00:00');
}

/**
 * The effects of a straight line, one after the other. A chain has one effect per level.
 *
 * @param  list<Effect>  $effects
 * @return list<Effect>
 */
function simLine(array $effects): array
{
    $line = [];
    $current = $effects;

    while ($current !== []) {
        $line[] = $current[0];
        $current = $current[0]->children;
    }

    return $line;
}

/** @return list<string> */
function simKinds(array $effects): array
{
    return array_map(fn (Effect $effect) => $effect->kind, simLine($effects));
}

function simReact(string $id, SimPerson $person): array
{
    return app(LeadMagnetMapObjects::class)->react($id, new SimEvent(SimEvent::REQUESTED), $person, simAt());
}

it('paths: a lead magnet that exists begins with its request', function () {
    simResource('atmung', Resource::TYPE_FILE, true);

    $paths = app(LeadMagnetMapObjects::class)->paths('atmung');

    expect($paths)->toHaveCount(1)
        ->and($paths[0])->toBeInstanceOf(SimEvent::class)
        ->and($paths[0]->type)->toBe(SimEvent::REQUESTED);
});

it('paths: a lead magnet that does not exist begins nowhere', function () {
    expect(app(LeadMagnetMapObjects::class)->paths('gibt-es-nicht'))->toBe([]);
});

it('react: the straight line for confirmation and delivery type', function (bool $confirm, string $type, array $kinds) {
    $resource = simResource('atmung', $type, $confirm);

    $line = simLine(simReact('atmung', SimPerson::unknown()));

    expect(array_map(fn (Effect $e) => $e->kind, $line))->toBe($kinds);

    $mails = array_values(array_filter($line, fn (Effect $e) => $e->kind === Effect::MAIL));
    $delivery = $type === Resource::TYPE_ACCESS ? '_access' : '';

    expect(end($mails)->label)->toBe(__('lead-magnets::mail.delivery_subject'.$delivery, ['title' => $resource->title]));

    if ($confirm) {
        expect($mails[0]->label)->toBe(__('lead-magnets::mail.confirmation_subject', ['title' => $resource->title]));

        $wait = array_values(array_filter($line, fn (Effect $e) => $e->kind === Effect::WAIT))[0];
        expect($wait->meta)->toBe(['duration_known' => false]);
    }

    $grant = end($line);
    expect($grant->kind)->toBe(Effect::EMIT)
        ->and($grant->emits->type)->toBe(SimEvent::GRANTED)
        ->and($grant->emits->via)->toBe(['triggers', 'unlocks'])
        ->and($grant->emits->data)->toBe(['slug' => 'atmung']);
})->with([
    'Bestätigung, Datei' => [true, Resource::TYPE_FILE, ['mail', 'wait', 'mail', 'emit']],
    'Bestätigung, Link' => [true, Resource::TYPE_LINK, ['mail', 'wait', 'mail', 'emit']],
    'Bestätigung, Zugang' => [true, Resource::TYPE_ACCESS, ['mail', 'wait', 'access', 'mail', 'emit']],
    'ohne Bestätigung, Datei' => [false, Resource::TYPE_FILE, ['mail', 'emit']],
    'ohne Bestätigung, Link' => [false, Resource::TYPE_LINK, ['mail', 'emit']],
    'ohne Bestätigung, Zugang' => [false, Resource::TYPE_ACCESS, ['access', 'mail', 'emit']],
]);

it('react: an access lead magnet names the product it grants', function () {
    simResource('atmung', Resource::TYPE_ACCESS, false);

    $access = simLine(simReact('atmung', SimPerson::unknown()))[0];

    expect($access->kind)->toBe(Effect::ACCESS)
        ->and($access->detail)->toBe(__('lead-magnets::resources.sim_access_detail', ['product' => 'kurs-atmung']))
        // The slug is the access she now holds: the next steps of her way can ask for it.
        ->and($access->meta)->toBe(['slug' => 'kurs-atmung']);
});

/** The series (an automation) the access magnet hands the delivery to; the bridge answers for it. */
function simSeries(string $handle, ?bool $live): void
{
    app()->instance(AutomationsBridge::class, new class($handle, $live) extends AutomationsBridge
    {
        public function __construct(private string $handle, private ?bool $live) {}

        public function isLive(string $handle): ?bool
        {
            return $handle === $this->handle ? $this->live : null;
        }
    });
}

it('react: access is granted before the mail that links to it', function () {
    simResource('atmung', Resource::TYPE_ACCESS, false);

    expect(simKinds(simReact('atmung', SimPerson::unknown())))->toBe(['access', 'mail', 'emit']);
});

it('react: a live series takes the delivery over, a switched off one does not, and one this person has run does not', function () {
    $resource = simResource('atmung', Resource::TYPE_ACCESS, false);
    $resource->forceFill(['delivery_series' => 'willkommen'])->save();

    simSeries('willkommen', true);
    $effects = simReact('atmung', SimPerson::unknown());

    expect(simKinds($effects))->toBe(['access', 'emit'])
        ->and($effects[0]->detail)->toContain('willkommen');

    expect(simKinds(simReact('atmung', SimPerson::known([], [], ['willkommen']))))->toBe(['access', 'mail', 'emit']);

    simSeries('willkommen', false);
    expect(simKinds(simReact('atmung', SimPerson::unknown())))->toBe(['access', 'mail', 'emit']);
});

it('react: a known person who holds the access gets only the delivery mail again', function () {
    simResource('atmung', Resource::TYPE_FILE, true);

    $effects = simReact('atmung', SimPerson::known([], ['atmung']));

    expect(simKinds($effects))->toBe(['mail'])
        ->and($effects[0]->label)->toBe(__('lead-magnets::mail.delivery_subject', ['title' => 'Atemübung']));
});

it('react: a known person who holds the access gets no confirmation, even for an access magnet', function () {
    simResource('atmung', Resource::TYPE_ACCESS, true);

    expect(simKinds(simReact('atmung', SimPerson::known([], ['atmung']))))->toBe(['mail']);
});

it('react: a known person without the access goes the normal way', function () {
    simResource('atmung', Resource::TYPE_FILE, true);

    expect(simKinds(simReact('atmung', SimPerson::known([], ['anderes']))))->toBe(['mail', 'wait', 'mail', 'emit']);
});

it('react: an unpublished lead magnet stops the path', function () {
    simResource('atmung', Resource::TYPE_FILE, true, published: false);

    $effects = simReact('atmung', SimPerson::unknown());

    expect(simKinds($effects))->toBe(['stop'])
        ->and($effects[0]->label)->toBe(__('lead-magnets::resources.sim_unpublished'));
});

it('react: a lead magnet that does not exist is unknown, not guessed', function () {
    $effects = simReact('gibt-es-nicht', SimPerson::unknown());

    expect(simKinds($effects))->toBe(['unknown'])
        ->and($effects[0]->label)->toBe(__('lead-magnets::resources.sim_missing'));
});

it('writes nothing: no statement that changes data, no mail, no job', function () {
    Mail::fake();
    Queue::fake();

    simResource('datei', Resource::TYPE_FILE, true);
    simResource('link', Resource::TYPE_LINK, false);
    simResource('zugang', Resource::TYPE_ACCESS, true);
    simResource('aus', Resource::TYPE_FILE, true, published: false);

    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements) {
        $statements[] = $query->sql;
    });

    $map = app(LeadMagnetMapObjects::class);

    foreach (['datei', 'link', 'zugang', 'aus', 'gibt-es-nicht'] as $id) {
        $map->paths($id);

        foreach ([SimPerson::unknown(), SimPerson::known([], [$id]), SimPerson::known()] as $person) {
            $map->react($id, new SimEvent(SimEvent::REQUESTED), $person, simAt());
        }
    }

    $writes = array_values(array_filter(
        $statements,
        fn (string $sql) => ! preg_match('/^\s*(select|pragma)\b/i', $sql),
    ));

    // The listener must have seen the reads, or the empty list proves nothing.
    expect($statements)->not->toBe([])
        ->and($writes)->toBe([]);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Queue::assertNothingPushed();
});

it('simulationAuthorized: without a user, no', function () {
    simResource('atmung', Resource::TYPE_FILE, true);

    expect(app(LeadMagnetMapObjects::class)->simulationAuthorized('atmung', null))->toBeFalse();
});

it('simulationAuthorized: a user without the right, no, even with a right for something else', function () {
    simResource('atmung', Resource::TYPE_FILE, true);
    $user = User::make()->email('plain@example.test');
    $user->save();

    Gate::before(fn ($u, $ability) => $ability === 'view automations' ? true : null);

    expect(app(LeadMagnetMapObjects::class)->simulationAuthorized('atmung', $user))->toBeFalse();
});

it('simulationAuthorized: with the permission to manage lead magnets, yes', function () {
    simResource('atmung', Resource::TYPE_FILE, true);
    $user = User::make()->email('sim@example.test');
    $user->save();

    Gate::before(fn ($u, $ability) => $ability === 'manage lead magnets');

    expect(app(LeadMagnetMapObjects::class)->simulationAuthorized('atmung', $user))->toBeTrue();
});
