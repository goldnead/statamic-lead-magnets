<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step three of the move: remove this addon's own grant state.
 *
 * Step two is not a migration. It is `php artisan lead-magnets:migrate-grants`,
 * and this file refuses to run until it has, because dropping these columns
 * before their contents are carried across destroys the only record of who had
 * access to what.
 *
 * WHY THE GUARD THROWS INSTEAD OF SKIPPING
 * ----------------------------------------
 * A migration that quietly did nothing would leave the schema half-moved and
 * the deploy green — the worst outcome, because the damage surfaces later as
 * "some downloads stopped working" with nothing in any log pointing back here.
 * Aborting stops the deploy at the one moment somebody is watching it, and the
 * message says exactly which command to run.
 *
 * The two-step upgrade is therefore:
 *
 *     php artisan migrate                      # this file aborts, by design
 *     php artisan lead-magnets:migrate-grants --dry-run
 *     php artisan lead-magnets:migrate-grants
 *     php artisan migrate                      # continues here
 *
 * A fresh install never sees any of it: there are no rows, the guard passes,
 * and the columns are created and dropped inside the same `migrate` run.
 *
 * `down()` restores the columns but not their contents. That is honest rather
 * than convenient: the data is in `entitlements` afterwards, and a rollback
 * that invented plausible values for `state` would be worse than one that
 * leaves them null.
 */
return new class extends Migration
{
    private const LEGACY_COLUMNS = ['state', 'confirmed_at', 'revoked_at', 'expires_at'];

    public function up(): void
    {
        if (! Schema::hasColumn('lead_magnet_grants', 'state')) {
            return;
        }

        $unmigrated = DB::table('lead_magnet_grants')->whereNull('entitlement_id')->count();

        if ($unmigrated > 0) {
            throw new RuntimeException(sprintf(
                '%d lead-magnet grant(s) still have no entitlement. Their access state would be '
                .'destroyed by this migration. Run `php artisan lead-magnets:migrate-grants --dry-run` '
                .'to see what would happen, then `php artisan lead-magnets:migrate-grants`, '
                .'then `php artisan migrate` again.',
                $unmigrated
            ));
        }

        // The index on `state` is dropped in its own statement. SQLite refuses
        // to drop a column an index still references, and Laravel's SQLite
        // grammar does not unpick that for you.
        Schema::table('lead_magnet_grants', function (Blueprint $table) {
            $table->dropIndex('lead_magnet_grants_state_index');
        });

        Schema::table('lead_magnet_grants', function (Blueprint $table) {
            $table->dropColumn(self::LEGACY_COLUMNS);
        });
    }

    public function down(): void
    {
        Schema::table('lead_magnet_grants', function (Blueprint $table) {
            $table->string('state', 16)->default('pending')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
        });
    }
};
