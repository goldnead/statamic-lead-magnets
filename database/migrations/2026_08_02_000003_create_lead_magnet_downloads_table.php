<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_magnet_downloads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->index();
            $table->unsignedBigInteger('grant_id')->index();

            $table->timestamp('downloaded_at')->nullable();

            // The address itself is never stored here — the grant already has
            // it, and a second copy is a second thing to erase on request.
            // The hash is enough to recognise "the same client again" without
            // holding personal data the audit has no use for.
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_magnet_downloads');
    }
};
