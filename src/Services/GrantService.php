<?php

namespace Goldnead\LeadMagnets\Services;

use Carbon\CarbonImmutable;
use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\LeadMagnets\Events\ResourceRequested;
use Goldnead\LeadMagnets\Models\Download;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Support\ConfirmationToken;
use Goldnead\LeadMagnets\Support\EmailNormalizer;
use Goldnead\LeadMagnets\Support\LeadMagnetSubject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The request and delivery flow. Access state belongs to entitlements.
 *
 * ## The division of labour
 *
 * This class writes the delivery record — the address, the confirmation
 * secret, the audit — and asks `goldnead/statamic-entitlements` for every
 * change of access. It never writes `status` on an entitlement and never
 * resolves state itself. The one entitlements column it does write is
 * `expires_at`, and only through {@see self::openWindow()}: setting the window
 * is supplying an input the resolver reads, not implementing a second copy of
 * the rules.
 *
 * ## The defect this class exists to keep fixed
 *
 * A grant waiting for a double opt-in has two clocks that look alike and are
 * not: "you have 72 hours to confirm" and "your access lasts a year". Version
 * 1.0.0 stored both in one column and had to overwrite one with the other at
 * activation — a single line, and without it every confirmed access expired
 * silently 72 hours later. It would have surfaced weeks on as "the download
 * link stopped working".
 *
 * They are two columns now, on two different rows. The confirmation deadline is
 * `lead_magnet_grants.confirm_expires_at` and belongs to the token; the access
 * window is `entitlements.expires_at` and is written at activation and never
 * before. There is no longer a value to overwrite, so there is no longer an
 * overwrite to forget. `tests/Feature/ActivationWindowTest.php` holds the line.
 */
class GrantService
{
    /**
     * Record a request for a resource.
     *
     * Repeatable by design. Asking twice for the same resource from the same
     * address updates the one row the unique index allows and mints a fresh
     * confirmation token — it does not create a second grant, and it does not
     * un-confirm a grant that is already active.
     *
     * @param  array<string, mixed>  $meta
     */
    public function request(Resource $resource, string $email, array $meta = []): Grant
    {
        $email = EmailNormalizer::normalize($email);

        $grant = Grant::query()
            ->where('resource_id', $resource->id)
            ->where('email', $email)
            ->first()
            ?? new Grant(['resource_id' => $resource->id, 'email' => $email, 'attempt' => 1]);

        $grant->requested_at = Carbon::now();
        $grant->meta = array_merge($grant->meta ?? [], $meta);
        $grant->save();
        $grant->setRelation('resource', $resource);

        $entitlement = $this->entitlementFor($grant, $resource);
        $state = $entitlement->state();

        // A revoked grant stays revoked. Someone withdrew this access on
        // purpose, and a form submission is not the place to overturn that —
        // it would let anyone who knows the address undo a moderation
        // decision. Reinstating is a Control Panel action.
        //
        // A scheduled grant is left alone for the mirror-image reason: an
        // operator set a start date, and a form submission does not move it.
        if ($state === EntitlementState::Revoked || $state === EntitlementState::Scheduled) {
            ResourceRequested::dispatch($grant);

            return $grant;
        }

        if ($state->grantsAccess()) {
            // Already confirmed. Nothing to confirm again — the caller
            // re-sends the delivery mail, which is what the visitor actually
            // wanted, and no second confirmation goes out.
            ResourceRequested::dispatch($grant);

            return $grant;
        }

        if ($state === EntitlementState::Expired) {
            // The window closed. A new request is a new window, so it gets a
            // new entitlement rather than a rewrite of the old one: the expired
            // row is a true record of an access period that happened, and
            // entitlements answers over all of a subject's grants as an OR, so
            // a second row is exactly how it expects repeat access to look.
            $this->reopen($grant, $resource);
        }

        if (! $resource->requires_confirmation) {
            // No confirmation asked for: the grant is born pending and claimed
            // in the same call, so activation runs through the one atomic path
            // and the delivery listener fires exactly once here too.
            $grant->forceFill(['token_hash' => null, 'confirm_expires_at' => null])->save();

            ResourceRequested::dispatch($grant);

            $activated = $this->activate($grant);

            $grant->refresh();
            $grant->setRelation('resource', $resource);
            $grant->justActivated = $activated;

            return $grant;
        }

        $token = ConfirmationToken::mint();

        $grant->forceFill([
            'token_hash' => ConfirmationToken::hash($token),
            'confirm_expires_at' => $this->confirmationDeadline(),
        ])->save();

        $grant->plainToken = $token;

        ResourceRequested::dispatch($grant);

        return $grant;
    }

    /**
     * Find the grant a confirmation token addresses.
     *
     * Looks up by hash, so an unknown token and a token for another brand are
     * the same non-answer. Returns the grant whatever state it is in — the
     * caller needs to tell "already confirmed" (show the page again) from
     * "no such token" (404), and only activate() may decide which one wins.
     */
    public function findByToken(string $token): ?Grant
    {
        if ($token === '') {
            return null;
        }

        return Grant::query()
            ->with(['resource', 'entitlement'])
            ->where('token_hash', ConfirmationToken::hash($token))
            ->first();
    }

    /**
     * pending -> active, exactly once. Returns whether this call was the one.
     *
     * Two statements, both conditional on the entitlement still being pending,
     * and the order between them matters:
     *
     * 1. Write the access window. Harmless on a pending row — pending grants
     *    nothing whatever the dates say — and it must already be in place when
     *    step 2 fires, because the listener that mails the download link builds
     *    that link against this window. A link signed before the window exists
     *    would outlive the access it was issued for.
     * 2. Claim the row through entitlements. The conditional UPDATE inside
     *    `claimPending()` is what makes a double-clicked link, a mail scanner
     *    prefetching the URL, a queue retry and two web workers at once produce
     *    one activation and one delivery mail.
     *
     * Step 1 carries the same `status = pending` condition, so a caller that has
     * already lost the race writes nothing — but its affected-row count is not
     * what decides the winner, and must not be. MySQL reports zero changed rows
     * for an UPDATE that sets a column to the value it already holds, and the
     * common case here is exactly that: a resource with no lifetime writes NULL
     * over NULL. Reading a winner out of that count passes on SQLite, which
     * counts matched rows instead, and silently activates nothing on MySQL.
     *
     * The winner is decided by step 2, where the status genuinely changes.
     */
    public function activate(Grant $grant): bool
    {
        $entitlement = $grant->entitlement()->first();

        if ($entitlement === null) {
            return false;
        }

        $resource = $grant->resource ?? Resource::query()->find($grant->resource_id);

        $this->openWindow($entitlement, $resource, onlyWhilePending: true);

        $entitlement->refresh();

        return Entitlements::claimPending($entitlement);
    }

    /**
     * Withdraw access, with a reason.
     *
     * The reason is not optional and not this addon's choice: entitlements
     * refuses a blank one, on the grounds that a revocation nobody can explain
     * six months later is a revocation whoever is on support that day undoes.
     */
    public function revoke(Grant $grant, string $reason): bool
    {
        $entitlement = $grant->entitlement()->first();

        if ($entitlement === null) {
            return false;
        }

        // The confirmation secret goes with the access. A pending link that
        // survived a revocation would activate the grant again on the next
        // click.
        $grant->forceFill(['token_hash' => null, 'confirm_expires_at' => null])->save();

        return Entitlements::revoke($entitlement, $reason);
    }

    /**
     * Put a revoked or expired grant back into service, without re-confirming.
     *
     * The address is already proven. Making somebody confirm twice because an
     * editor changed their mind is a support problem dressed up as diligence.
     */
    public function reinstate(Grant $grant): Grant
    {
        $entitlement = $grant->entitlement()->first();

        if ($entitlement === null) {
            return $grant;
        }

        if ($entitlement->state() === EntitlementState::Revoked) {
            Entitlements::restore($entitlement);
            $entitlement->refresh();
        }

        if ($entitlement->state() === EntitlementState::Pending) {
            // Never confirmed at all: reinstating means confirming on the
            // reader's behalf, which is the same atomic path the link takes.
            $this->activate($grant);

            return $grant->refresh();
        }

        if (! $entitlement->state()->grantsAccess()) {
            // Restored into a window that had meanwhile closed, or expired
            // without ever being revoked. Either way it needs a fresh window,
            // not a fresh confirmation.
            $this->openWindow($entitlement, $grant->resource);
        }

        return $grant->refresh();
    }

    /**
     * Clear confirmation secrets whose window has closed.
     *
     * All that is left of the old sweep. Expiry of *access* is derived from the
     * clock by entitlements and needs no housekeeping at all; what still ages
     * is the token, and a hash that can no longer be redeemed has no reason to
     * stay in the database.
     *
     * Nothing gates on this having run: `findByToken()` hands the grant back
     * and the controller checks the deadline, so a site that never schedules
     * the sweep is safe and merely untidy.
     */
    public function sweepExpiredTokens(): int
    {
        return Grant::query()
            ->whereNotNull('token_hash')
            ->whereNotNull('confirm_expires_at')
            ->where('confirm_expires_at', '<', Carbon::now())
            ->update(['token_hash' => null]);
    }

    /**
     * Count a redemption and stamp the audit row, in one transaction.
     *
     * The increment and the audit row have to agree or the audit is fiction,
     * and the increment is what enforces `max_downloads`. Both in one
     * transaction, the counter incremented in the database rather than read
     * and written back, so two simultaneous downloads count as two.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordDownload(Grant $grant, array $context = []): Download
    {
        return DB::transaction(function () use ($grant, $context) {
            Grant::query()->whereKey($grant->getKey())->increment('download_count');

            $grant->download_count++;

            return $grant->downloads()->create([
                'brand_id' => $grant->brand_id,
                'downloaded_at' => Carbon::now(),
                'ip_hash' => isset($context['ip'])
                    ? hash('sha256', (string) $context['ip'])
                    : null,
                'user_agent' => isset($context['user_agent'])
                    ? mb_substr((string) $context['user_agent'], 0, 255)
                    : null,
            ]);
        });
    }

    // --------------------------------------------------------------- internals

    /**
     * The entitlement behind a grant, created pending if it has none yet.
     *
     * `grantPending()` is called with no expiry at all. That is the structural
     * half of the fix described in the class docblock: a pending entitlement
     * has no access window, so there is nothing for activation to overwrite and
     * no way for the confirmation deadline to leak into the access period.
     */
    protected function entitlementFor(Grant $grant, Resource $resource): Entitlement
    {
        if ($grant->entitlement_id !== null) {
            $existing = $grant->entitlement()->first();

            if ($existing !== null) {
                $grant->setRelation('entitlement', $existing);

                return $existing;
            }
        }

        return $this->writePending($grant, $resource);
    }

    /** Open a new access period for a grant whose previous one expired. */
    protected function reopen(Grant $grant, Resource $resource): Entitlement
    {
        $grant->forceFill(['attempt' => $grant->attempt + 1])->save();

        return $this->writePending($grant, $resource);
    }

    protected function writePending(Grant $grant, Resource $resource): Entitlement
    {
        $entitlement = Entitlements::grantPending(
            LeadMagnetSubject::for($grant->email),
            $resource->handle,
            LeadMagnetSubject::source(),
            // The attempt number is the source reference, which makes the
            // entitlements unique key mean "one grant per address, resource and
            // access period". Absence would be the empty string, and a second
            // period could then never be written.
            (string) $grant->attempt,
            null,
            ['lead_magnet_grant_id' => $grant->id],
        );

        $grant->forceFill(['entitlement_id' => $entitlement->getKey()])->save();
        $grant->setRelation('entitlement', $entitlement);

        return $entitlement;
    }

    /**
     * Write the access window onto the entitlement.
     *
     * The resource's own lifetime, or none at all when it sets none.
     *
     * A query-builder UPDATE rather than a model save, because the `pending`
     * guard has to be part of the same statement — a read-then-write here is
     * the race this whole path is built to avoid. The value is formatted by
     * hand for the same reason: a builder UPDATE runs no casts.
     *
     * The affected-row count is deliberately not returned. See
     * {@see self::activate()} for why it would be the wrong thing to trust.
     */
    protected function openWindow(Entitlement $entitlement, ?Resource $resource, bool $onlyWhilePending = false): void
    {
        $days = $resource?->grantTtlDays();

        $expiresAt = $days === null
            ? null
            : CarbonImmutable::now('UTC')->addDays($days)->format('Y-m-d H:i:s');

        $query = Entitlement::query()->whereKey($entitlement->getKey());

        if ($onlyWhilePending) {
            $query->where('status', EntitlementState::Pending->value);
        }

        $query->update(['expires_at' => $expiresAt]);
    }

    protected function confirmationDeadline(): ?Carbon
    {
        $hours = (int) config('lead-magnets.requests.confirmation_ttl_hours', 72);

        return $hours > 0 ? Carbon::now()->addHours($hours) : null;
    }
}
