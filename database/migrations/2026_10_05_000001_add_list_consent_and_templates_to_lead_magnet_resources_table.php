<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One confirmation for the file and the list, and mail templates per resource.
 *
 * `list_via_confirmation` couples the resource's mailing list to its own
 * confirmation: the reader confirms once and is subscribed without marketing's
 * second mail. It only takes effect together with a list, a confirmation and a
 * disclosure (`list_consent_text`), see `Resource::couplesListToConfirmation()`.
 *
 * `confirmation_template` and `delivery_template` name an email-templates slug
 * for this resource; empty falls back to the configured one.
 *
 * Every existing row keeps false and nulls, so no resource changes behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_magnet_resources', function (Blueprint $table) {
            $table->boolean('list_via_confirmation')->default(false)->after('marketing_list');
            $table->text('list_consent_text')->nullable()->after('list_via_confirmation');
            $table->string('confirmation_template', 191)->nullable()->after('list_consent_text');
            $table->string('delivery_template', 191)->nullable()->after('confirmation_template');
        });
    }

    public function down(): void
    {
        Schema::table('lead_magnet_resources', function (Blueprint $table) {
            $table->dropColumn(['list_via_confirmation', 'list_consent_text', 'confirmation_template', 'delivery_template']);
        });
    }
};
