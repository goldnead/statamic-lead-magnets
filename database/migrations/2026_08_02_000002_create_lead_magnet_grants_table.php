<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_magnet_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->index();
            $table->unsignedBigInteger('resource_id')->index();

            // Normalised, lower-cased. 191 for the same reason as the resource
            // handle: this column carries the widest composite in the package.
            $table->string('email', 191);

            // The contact this grant resolved to, when leadhub is installed.
            // Nullable and unconstrained on purpose: the addon has to work
            // with no contact store at all, and a foreign key to a table that
            // may not exist is not a constraint, it is an install failure.
            $table->string('contact_id', 64)->nullable()->index();

            $table->string('state', 16)->default('pending')->index();

            // The confirmation secret is stored hashed. A leaked database row
            // must not be a working confirmation link, and nothing in the
            // application ever needs to read the token back — it is only ever
            // compared against one the visitor presents.
            $table->string('token_hash', 64)->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->unsignedInteger('download_count')->default(0);
            $table->json('meta')->nullable();

            $table->timestamps();

            // One grant per address per resource per brand. This is what makes
            // a repeated request an update rather than a second pending grant,
            // and it is the row-level half of the idempotency guarantee — the
            // other half is the conditional UPDATE in GrantService::activate().
            $table->unique(['brand_id', 'resource_id', 'email'], 'lead_magnet_grants_unique');

            // The confirmation lookup. Unique across brands because a token is
            // a secret, not an identifier: two grants sharing one would make
            // the brand derivation on the public confirm route ambiguous.
            $table->unique('token_hash', 'lead_magnet_grants_token_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_magnet_grants');
    }
};
