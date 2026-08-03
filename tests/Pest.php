<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Services\GrantService;
use Goldnead\LeadMagnets\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * A published, file-backed resource with a real file behind it.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeResource(array $attributes = []): Resource
{
    $attributes = array_merge([
        'handle' => 'warm_up',
        'title' => 'Warm-up routine',
        'delivery_type' => Resource::TYPE_FILE,
        'file_path' => 'warm-up.txt',
        'requires_confirmation' => true,
        'published' => true,
    ], $attributes);

    if (($attributes['delivery_type'] ?? null) === Resource::TYPE_FILE && $attributes['file_path']) {
        Storage::disk('lead-magnets')
            ->put($attributes['file_path'], 'the file itself');
    }

    return Resource::query()->create($attributes);
}

/**
 * A grant in a given state, built through the real path.
 *
 * There is no `state` column to seed any more, and that is the point: a fixture
 * that wrote one would be a second writer of access state, which is exactly the
 * arrangement this package moved away from. So the helper requests, confirms
 * and — where asked — revokes or ages the grant, using the same calls a visitor
 * and an editor make.
 */
function makeGrant(Resource $resource, string $email, EntitlementState $state = EntitlementState::Active): Grant
{
    $grants = app(GrantService::class);

    $grant = $grants->request($resource, $email);

    if ($state === EntitlementState::Pending) {
        return Grant::query()->with('entitlement')->find($grant->id);
    }

    $grants->activate($grant);

    $grant = Grant::query()->with(['entitlement', 'resource'])->find($grant->id);

    if ($state === EntitlementState::Revoked) {
        $grants->revoke($grant, 'Fixture');
    }

    if ($state === EntitlementState::Expired) {
        $grant->entitlement->forceFill(['expires_at' => now()->subDay()])->save();
    }

    return Grant::query()->with(['entitlement', 'resource'])->find($grant->id);
}

/**
 * The plaintext confirmation token for the most recent confirmation mail.
 *
 * Read out of the sent mail rather than out of the database, because the
 * database only holds the hash — which is the property under test. A helper
 * that reached into the model would happily pass for an addon that never
 * mailed anything.
 */
function tokenFromLastConfirmationMail(): ?string
{
    $messages = app('mailer')->getSymfonyTransport()->messages();

    for ($i = count($messages) - 1; $i >= 0; $i--) {
        // Quoted-printable wraps long lines with a soft break, which lands in
        // the middle of a 64-character token. Decode before matching or the
        // helper reports "no token" for a mail that carries one.
        $body = quoted_printable_decode($messages[$i]->getMessage()->toString());

        if (preg_match('#/confirm/([a-f0-9]{64})#', $body, $matches)) {
            return $matches[1];
        }
    }

    return null;
}

/** The signed download URL out of the most recent delivery mail. */
function downloadUrlFromLastDeliveryMail(): ?string
{
    $messages = app('mailer')->getSymfonyTransport()->messages();

    for ($i = count($messages) - 1; $i >= 0; $i--) {
        $body = quoted_printable_decode($messages[$i]->getMessage()->toString());

        if (preg_match('#(https?://[^\s"<>]*?/download/\d+\?[^\s"<>]+)#', $body, $matches)) {
            return html_entity_decode($matches[1]);
        }
    }

    return null;
}

function sentMailCount(): int
{
    return count(app('mailer')->getSymfonyTransport()->messages());
}
