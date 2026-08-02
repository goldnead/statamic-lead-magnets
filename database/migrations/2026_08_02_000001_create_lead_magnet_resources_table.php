<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_magnet_resources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->index();

            // 191, not 255, at utf8mb4's four bytes per character: the
            // composite index below would otherwise sit at 1028 bytes for no
            // reason. tests/Unit/IndexKeyLengthTest.php measures the keys
            // rather than trusting this comment.
            $table->string('handle', 191);
            $table->string('title');
            $table->text('description')->nullable();

            // `file` serves bytes from disk, `link` redirects. Both go through
            // the same signed, audited route — a link is not less gated.
            $table->string('delivery_type', 16)->default('file');
            $table->string('file_path')->nullable();
            $table->string('file_disk', 64)->nullable();
            $table->text('link_url')->nullable();

            $table->boolean('requires_confirmation')->default(true);
            $table->boolean('published')->default(true);

            $table->unsignedInteger('link_ttl')->nullable();
            $table->unsignedInteger('max_downloads')->nullable();
            $table->unsignedInteger('grant_ttl_days')->nullable();

            // Tags written onto the contact when the grant activates, and the
            // mailing list the confirmed address is subscribed to. Both are
            // inert without the sibling addon that reads them.
            $table->json('tags')->nullable();
            $table->string('marketing_list', 191)->nullable();

            $table->timestamps();

            // Globally unique, not unique per brand.
            //
            // The public request endpoint is opened with no session, so no
            // brand is current, and the only thing the visitor carries is the
            // handle their form names. The brand is derived from it. That
            // derivation is safe exactly as long as a handle addresses one
            // resource across all brands — make it unique per brand instead
            // and the same form field points at two resources, which
            // brand-context answers by throwing rather than guessing.
            //
            // The cost is real and deliberate: two brands cannot both call a
            // resource `warmup-routine`. Marketing made the same trade for
            // list handles, for the same reason.
            $table->unique('handle', 'lead_magnet_resources_handle_unique');
            $table->index(['brand_id', 'published'], 'lead_magnet_resources_brand_published_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_magnet_resources');
    }
};
