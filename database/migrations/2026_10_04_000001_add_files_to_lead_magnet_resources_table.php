<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An ordered file list per resource, and which file a download was.
 *
 * `files` is a JSON list of `{key, path, label, group}`. It is nullable and
 * stays null on every existing resource: a resource without a list falls back
 * to its `file_path`, read as a list of one (see `Resource::fileList()`), so
 * this migration changes no row and no old freebie notices it.
 *
 * `file_key` on a download names the file that was fetched, so the download cap
 * can be counted per file. It is null for every download made before this
 * existed and for every single-file freebie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_magnet_resources', function (Blueprint $table) {
            $table->json('files')->nullable()->after('file_disk');
        });

        Schema::table('lead_magnet_downloads', function (Blueprint $table) {
            $table->string('file_key', 16)->nullable()->after('grant_id');
            $table->index(['grant_id', 'file_key'], 'lead_magnet_downloads_grant_file_index');
        });
    }

    public function down(): void
    {
        Schema::table('lead_magnet_downloads', function (Blueprint $table) {
            $table->dropIndex('lead_magnet_downloads_grant_file_index');
            $table->dropColumn('file_key');
        });

        Schema::table('lead_magnet_resources', function (Blueprint $table) {
            $table->dropColumn('files');
        });
    }
};
