<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\LeadMagnets\Events\ResourceDelivered;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Support\LeadMagnetSubject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/*
 * `lead-magnets:migrate-grants`, the one-time move of 1.x grant state into
 * goldnead/statamic-entitlements.
 *
 * The suite runs against the finished schema, so a 1.x row has to be
 * reconstructed here: the legacy columns are put back, filled the way 1.0.0
 * filled them, and the command is then asked to do its job. That is more
 * setup than a fixture, and it is the only way to test the thing that actually
 * matters — reading real old data, not a hand-written approximation of it.
 */

beforeEach(function () {
    // Put the 1.x columns back and unlink the grants, exactly as an install
    // that has run the first migration but not the command would look.
    Schema::table('lead_magnet_grants', function ($table) {
        $table->string('state', 16)->default('pending')->index();
        $table->timestamp('confirmed_at')->nullable();
        $table->timestamp('revoked_at')->nullable();
        $table->timestamp('expires_at')->nullable();
    });
});

/** A 1.x grant row: no entitlement, state in its own column. */
function legacyGrant(string $email, string $state, array $attributes = []): int
{
    $resource = Resource1x();

    return DB::table('lead_magnet_grants')->insertGetId(array_merge([
        'brand_id' => 0,
        'resource_id' => $resource->id,
        'email' => $email,
        'state' => $state,
        'attempt' => 1,
        'requested_at' => Carbon::now()->subDays(30),
        'confirmed_at' => $state === 'pending' ? null : Carbon::now()->subDays(29),
        'download_count' => 0,
        'created_at' => Carbon::now()->subDays(30),
        'updated_at' => Carbon::now()->subDays(30),
    ], $attributes));
}

function Resource1x(): Goldnead\LeadMagnets\Models\Resource
{
    return Goldnead\LeadMagnets\Models\Resource::query()->firstOrCreate(
        ['handle' => 'warm_up'],
        [
            'title' => 'Warm-up routine',
            'delivery_type' => 'file',
            'file_path' => 'warm-up.txt',
            'requires_confirmation' => true,
            'published' => true,
        ]
    );
}

it('carries every legacy state into entitlements', function () {
    legacyGrant('pending@example.com', 'pending', ['expires_at' => Carbon::now()->addDay()]);
    legacyGrant('active@example.com', 'active');
    legacyGrant('expired@example.com', 'expired', ['expires_at' => Carbon::now()->subDay()]);
    legacyGrant('revoked@example.com', 'revoked', ['revoked_at' => Carbon::now()->subDays(2)]);

    $this->artisan('lead-magnets:migrate-grants')->assertSuccessful();

    $byEmail = Grant::query()->with('entitlement')->get()->keyBy('email');

    expect($byEmail['pending@example.com']->state())->toBe(EntitlementState::Pending)
        ->and($byEmail['active@example.com']->state())->toBe(EntitlementState::Active)
        ->and($byEmail['expired@example.com']->state())->toBe(EntitlementState::Expired)
        ->and($byEmail['revoked@example.com']->state())->toBe(EntitlementState::Revoked);

    // Every one of them is linked, and the subject is the hashed address.
    foreach ($byEmail as $email => $grant) {
        expect($grant->entitlement_id)->not->toBeNull()
            ->and($grant->entitlement->subject_id)->toBe(LeadMagnetSubject::id($email))
            ->and($grant->entitlement->source)->toBe('lead_magnet');
    }
});

it('is idempotent: a second run changes nothing', function () {
    legacyGrant('pending@example.com', 'pending', ['expires_at' => Carbon::now()->addDay()]);
    legacyGrant('active@example.com', 'active');
    legacyGrant('revoked@example.com', 'revoked', ['revoked_at' => Carbon::now()->subDays(2)]);

    $this->artisan('lead-magnets:migrate-grants')->assertSuccessful();

    $after = Entitlement::query()->orderBy('id')->get()
        ->map(fn (Entitlement $e) => $e->only([
            'id', 'subject_type', 'subject_id', 'product_slug', 'source', 'source_ref',
            'status', 'starts_at', 'expires_at', 'revoked_at', 'revoked_reason',
        ]))->toArray();

    $grantsAfter = Grant::query()->orderBy('id')->pluck('entitlement_id', 'id')->all();

    $this->artisan('lead-magnets:migrate-grants')->assertSuccessful();

    $again = Entitlement::query()->orderBy('id')->get()
        ->map(fn (Entitlement $e) => $e->only([
            'id', 'subject_type', 'subject_id', 'product_slug', 'source', 'source_ref',
            'status', 'starts_at', 'expires_at', 'revoked_at', 'revoked_reason',
        ]))->toArray();

    expect($again)->toEqual($after)
        ->and(Grant::query()->orderBy('id')->pluck('entitlement_id', 'id')->all())->toBe($grantsAfter)
        ->and(Entitlement::query()->count())->toBe(3);
});

it('writes nothing on a dry run and reports what it would do', function () {
    legacyGrant('pending@example.com', 'pending');
    legacyGrant('active@example.com', 'active');

    $this->artisan('lead-magnets:migrate-grants --dry-run')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(Entitlement::query()->count())->toBe(0)
        ->and(Grant::query()->whereNotNull('entitlement_id')->count())->toBe(0);

    // And the real run afterwards still does the whole job: a dry run must not
    // half-mark anything.
    $this->artisan('lead-magnets:migrate-grants')->assertSuccessful();

    expect(Entitlement::query()->count())->toBe(2);
});

it('mails nobody', function () {
    // The failure this guards against is an upgrade that delivers the entire
    // back catalogue. Historical rows are written straight to their final
    // state, so `EntitlementGranted` carries no previous state and the delivery
    // listener — which only acts on a transition out of Pending — stays quiet.
    Event::fake([ResourceDelivered::class]);

    legacyGrant('active@example.com', 'active');
    legacyGrant('expired@example.com', 'expired', ['expires_at' => Carbon::now()->subDay()]);

    $before = sentMailCount();

    $this->artisan('lead-magnets:migrate-grants')->assertSuccessful();

    expect(sentMailCount())->toBe($before);

    Event::assertNotDispatched(ResourceDelivered::class);
});

it('keeps the confirmation deadline on the grant and off the entitlement', function () {
    $deadline = Carbon::now()->addHours(12);

    legacyGrant('pending@example.com', 'pending', ['expires_at' => $deadline]);

    $this->artisan('lead-magnets:migrate-grants')->assertSuccessful();

    $grant = Grant::query()->with('entitlement')->sole();

    // The whole point of the two columns. A 1.x pending row kept its
    // confirmation deadline in `expires_at`; carrying that value onto the
    // entitlement would reintroduce the defect the move was meant to close.
    expect($grant->confirm_expires_at->timestamp)->toBe($deadline->timestamp)
        ->and($grant->entitlement->expires_at)->toBeNull();
});

it('skips a grant whose resource is gone and says so', function () {
    $id = legacyGrant('orphan@example.com', 'active');

    DB::table('lead_magnet_grants')->where('id', $id)->update(['resource_id' => 9999]);

    $this->artisan('lead-magnets:migrate-grants')
        ->expectsOutputToContain('no longer exists')
        ->assertSuccessful();

    expect(Entitlement::query()->count())->toBe(0);
});

it('refuses to drop the legacy columns while a grant is unmigrated', function () {
    legacyGrant('active@example.com', 'active');

    $migration = require __DIR__.'/../../database/migrations/'
        .'2026_08_03_000002_drop_legacy_grant_state_from_lead_magnet_grants_table.php';

    // Aborting is the design. A migration that quietly skipped would leave the
    // schema half-moved and the deploy green, and the damage would surface
    // weeks later as "some downloads stopped working".
    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'lead-magnets:migrate-grants');

    expect(Schema::hasColumn('lead_magnet_grants', 'state'))->toBeTrue();
});

it('drops the legacy columns once every grant has moved', function () {
    legacyGrant('active@example.com', 'active');

    $this->artisan('lead-magnets:migrate-grants')->assertSuccessful();

    $migration = require __DIR__.'/../../database/migrations/'
        .'2026_08_03_000002_drop_legacy_grant_state_from_lead_magnet_grants_table.php';

    $migration->up();

    expect(Schema::hasColumn('lead_magnet_grants', 'state'))->toBeFalse()
        ->and(Schema::hasColumn('lead_magnet_grants', 'confirmed_at'))->toBeFalse()
        ->and(Schema::hasColumn('lead_magnet_grants', 'revoked_at'))->toBeFalse()
        ->and(Schema::hasColumn('lead_magnet_grants', 'expires_at'))->toBeFalse();

    // And the migrated grant still works.
    expect(Grant::query()->with('entitlement')->sole()->state())->toBe(EntitlementState::Active);

    // Running the command again on the finished schema is a no-op, not a crash.
    $this->artisan('lead-magnets:migrate-grants')->assertSuccessful();
});
