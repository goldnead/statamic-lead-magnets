<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access instead of a file, and an optional mail series instead of the delivery mail.
 *
 * `access_product` is the product slug the confirmed reader is granted (a course,
 * a piece of material); `access_path` is where the sign-in link lands. Both are
 * read only when `delivery_type` is `access`. `delivery_series` names an
 * automation that replaces the delivery mail for a first-time reader.
 *
 * Every existing row keeps its nulls, so no resource changes behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_magnet_resources', function (Blueprint $table) {
            $table->string('access_product', 191)->nullable()->after('link_url');
            $table->string('access_path', 255)->nullable()->after('access_product');
            $table->string('delivery_series', 191)->nullable()->after('delivery_template');
        });
    }

    public function down(): void
    {
        Schema::table('lead_magnet_resources', function (Blueprint $table) {
            $table->dropColumn(['access_product', 'access_path', 'delivery_series']);
        });
    }
};
