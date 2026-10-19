<?php

use Goldnead\LeadMagnets\Integrations\Map\LeadMagnetMapObjects;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The page the sign-up form of a lead magnet stands on.
 *
 * `page_entry` is the id of a Statamic entry. The funnels map draws the edge
 * from that page to the lead magnet ({@see LeadMagnetMapObjects::edges()}).
 * The addon never reads the page itself; the id is only a pointer.
 *
 * Every existing row keeps null, so no resource changes behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('lead_magnet_resources', 'page_entry')) {
            return;
        }

        Schema::table('lead_magnet_resources', function (Blueprint $table) {
            $table->string('page_entry', 191)->nullable()->after('delivery_series');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('lead_magnet_resources', 'page_entry')) {
            return;
        }

        Schema::table('lead_magnet_resources', function (Blueprint $table) {
            $table->dropColumn('page_entry');
        });
    }
};
