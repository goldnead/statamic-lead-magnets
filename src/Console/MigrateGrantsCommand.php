<?php

namespace Goldnead\LeadMagnets\Console;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Concerns\RunsForEachBrand;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Support\LeadMagnetSubject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Carries 1.x grant state into `goldnead/statamic-entitlements`.
 *
 * A command and not a migration, deliberately. It writes through the
 * entitlements API rather than into its table, which means the unique key, the
 * state rules and the announcement markers are applied by the package that owns
 * them; a schema migration doing raw INSERTs would be a second writer with its
 * own idea of what a valid grant looks like. It also means an operator chooses
 * when it runs, can rehearse it, and can stop.
 *
 * ## Idempotent
 *
 * Twice over. A row that already carries an `entitlement_id` is not a
 * candidate, so a second run finds nothing to do; and `Entitlements::grant()`
 * is itself idempotent on its unique tuple, so even a row processed twice
 * through some other route produces one entitlement rather than two.
 *
 * ## It sends nothing
 *
 * Historical rows are written straight to their final state, so
 * `EntitlementGranted` fires with no previous state — and the delivery listener
 * only acts on a transition out of Pending. Nothing needs to be disabled and
 * nothing needs to be remembered: an upgrade does not mail the back catalogue.
 *
 * ## The state mapping
 *
 * | 1.x `state` | becomes                                                        |
 * |-------------|----------------------------------------------------------------|
 * | `pending`   | a pending entitlement, no access window; the confirmation       |
 * |             | deadline stays on the grant row where it belongs               |
 * | `active`    | active, starting when it was confirmed, ending when it did      |
 * | `expired`   | the same, with an end date already in the past — the resolver   |
 * |             | derives Expired from the clock, so nothing writes that word     |
 * | `revoked`   | granted and then revoked, with a reason, so the revocation is   |
 * |             | a real recorded act rather than a status nobody can explain     |
 *
 * `expired` is not stored as a status because entitlements does not store it:
 * three of its six states are facts about the clock, and writing them down
 * would create a row whose stored status can go stale.
 */
class MigrateGrantsCommand extends Command
{
    use RunsForEachBrand;

    protected $signature = 'lead-magnets:migrate-grants
        {--dry-run : Report what would change and write nothing}
        {--brand= : Restrict to one brand}';

    protected $description = 'Move 1.x lead-magnet grant state into goldnead/statamic-entitlements';

    public function handle(): int
    {
        if (! Schema::hasColumn('lead_magnet_grants', 'state')) {
            $this->info(__('lead-magnets::console.migrate_already_done'));

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        return $this->forEachBrand(function () use ($dryRun): int {
            $candidates = Grant::query()->whereNull('entitlement_id')->orderBy('id')->get();

            if ($candidates->isEmpty()) {
                $this->info(__('lead-magnets::console.migrate_nothing'));

                return self::SUCCESS;
            }

            $moved = 0;
            $skipped = 0;
            $tally = [];

            foreach ($candidates as $grant) {
                $resource = Resource::query()->find($grant->resource_id);

                if ($resource === null) {
                    // A grant whose resource is gone has nothing to be
                    // entitled to. Reported rather than silently dropped.
                    $this->warn(__('lead-magnets::console.migrate_orphan', ['id' => (string) $grant->id]));
                    $skipped++;

                    continue;
                }

                $state = (string) ($grant->getAttribute('state') ?? 'pending');
                $tally[$state] = ($tally[$state] ?? 0) + 1;

                if ($dryRun) {
                    $moved++;

                    continue;
                }

                $this->carry($grant, $resource, $state);
                $moved++;
            }

            foreach ($tally as $state => $count) {
                $this->line(sprintf('  %-8s %d', $state, $count));
            }

            $this->info(__($dryRun ? 'lead-magnets::console.migrate_dry_run' : 'lead-magnets::console.migrated', [
                'moved' => (string) $moved,
                'skipped' => (string) $skipped,
            ]));

            return self::SUCCESS;
        });
    }

    /** Write one grant's legacy state into entitlements and link the two. */
    protected function carry(Grant $grant, Resource $resource, string $state): void
    {
        $subject = LeadMagnetSubject::for($grant->email);
        $source = LeadMagnetSubject::source();
        $meta = ['lead_magnet_grant_id' => $grant->id, 'migrated_from' => $state];

        if ($state === 'pending') {
            $entitlement = Entitlements::grantPending($subject, $resource->handle, $source, '1', null, $meta);

            $grant->forceFill([
                'entitlement_id' => $entitlement->getKey(),
                'attempt' => 1,
                'confirm_expires_at' => $grant->confirm_expires_at ?? $this->instant($grant, 'expires_at'),
            ])->save();

            return;
        }

        $entitlement = Entitlements::grant(
            $subject,
            $resource->handle,
            $source,
            '1',
            // The confirmation is when access began. Falling back to the
            // request keeps a row that predates the confirmed_at column from
            // starting at "now" and looking newer than it is.
            $this->instant($grant, 'confirmed_at') ?? $this->instant($grant, 'requested_at'),
            $this->instant($grant, 'expires_at'),
            null,
            $meta,
        );

        if ($state === 'revoked') {
            Entitlements::revoke(
                $entitlement,
                __('lead-magnets::console.migrated_revocation', [
                    'at' => (string) ($this->instant($grant, 'revoked_at')?->toDateString() ?? '—'),
                ]),
            );
        }

        $grant->forceFill(['entitlement_id' => $entitlement->getKey(), 'attempt' => 1])->save();
    }

    /**
     * Read a legacy timestamp column that the model no longer casts.
     *
     * The casts went with the columns. Reading them back as strings and parsing
     * here keeps the model free of attributes that are about to stop existing.
     */
    protected function instant(Grant $grant, string $column): ?CarbonImmutable
    {
        $value = $grant->getAttribute($column);

        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? CarbonImmutable::instance($value)->utc()
            : CarbonImmutable::parse((string) $value)->utc();
    }
}
