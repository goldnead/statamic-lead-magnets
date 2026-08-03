<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step one of the move to `goldnead/statamic-entitlements`: make room.
 *
 * This migration only adds. It drops nothing and rewrites nothing, so it is
 * safe to deploy on its own and safe to roll back. The legacy columns
 * (`state`, `confirmed_at`, `revoked_at`, `expires_at`) are still here
 * afterwards and still hold the truth for every existing row — the backfill
 * command reads them, and only once every row has been carried across does the
 * next migration remove them.
 *
 * Three columns arrive.
 *
 * `entitlement_id` is the link, and it is unique. One grant, one entitlement:
 * two grant rows pointing at the same entitlement would mean two delivery
 * records sharing one access decision, and revoking one would silently revoke
 * the other.
 *
 * `attempt` numbers the access periods of a single grant. It becomes the
 * entitlement's `source_ref`, which is part of that table's unique key, so a
 * reader whose year of access ran out and who asks again gets a second
 * entitlement rather than a rewrite of the first. The expired row is a true
 * record of a period that happened, and entitlements answers over all of a
 * subject's grants as an OR — a second row is exactly the shape it expects.
 *
 * `confirm_expires_at` is the deadline of the confirmation link, and it is here
 * rather than on the entitlement on purpose. Version 1.0.0 kept the
 * confirmation window and the access lifetime in one column and overwrote one
 * with the other at activation; without that overwrite every confirmed access
 * would have expired 72 hours later, silently. Two clocks that mean different
 * things now live in two columns on two different rows, so there is no longer
 * an overwrite to forget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_magnet_grants', function (Blueprint $table) {
            $table->unsignedBigInteger('entitlement_id')->nullable()->after('contact_id');
            $table->unsignedInteger('attempt')->default(1)->after('entitlement_id');
            $table->timestamp('confirm_expires_at')->nullable()->after('requested_at');

            $table->unique('entitlement_id', 'lead_magnet_grants_entitlement_unique');
        });

        // Carry the confirmation deadline of every still-waiting request across
        // before its column changes meaning. For a pending 1.x row `expires_at`
        // *was* the confirmation window; for every other state it was the access
        // window and belongs to the entitlement, which the backfill writes.
        if (Schema::hasColumn('lead_magnet_grants', 'state')) {
            DB::table('lead_magnet_grants')
                ->where('state', 'pending')
                ->whereNotNull('expires_at')
                ->update(['confirm_expires_at' => DB::raw('expires_at')]);
        }
    }

    public function down(): void
    {
        Schema::table('lead_magnet_grants', function (Blueprint $table) {
            $table->dropUnique('lead_magnet_grants_entitlement_unique');
            $table->dropColumn(['entitlement_id', 'attempt', 'confirm_expires_at']);
        });
    }
};
