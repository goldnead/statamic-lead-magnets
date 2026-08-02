<?php

namespace Goldnead\LeadMagnets\Services;

use Goldnead\LeadMagnets\Events\ResourceConfirmed;
use Goldnead\LeadMagnets\Events\ResourceRequested;
use Goldnead\LeadMagnets\GrantState;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Support\ConfirmationToken;
use Goldnead\LeadMagnets\Support\EmailNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The state machine. Everything that changes a grant's state goes through here.
 */
class GrantService
{
    /**
     * Record a request for a resource.
     *
     * Repeatable by design. Asking twice for the same resource from the same
     * address updates the one row the unique index allows and mints a fresh
     * confirmation token — it does not create a second pending grant, and it
     * does not un-confirm a grant that is already active.
     *
     * @param  array<string, mixed>  $meta
     */
    public function request(Resource $resource, string $email, array $meta = []): Grant
    {
        $email = EmailNormalizer::normalize($email);

        $grant = Grant::query()
            ->where('resource_id', $resource->id)
            ->where('email', $email)
            ->first();

        if (! $grant) {
            $grant = new Grant([
                'resource_id' => $resource->id,
                'email' => $email,
                'state' => GrantState::PENDING,
            ]);
        }

        $grant->requested_at = Carbon::now();
        $grant->meta = array_merge($grant->meta ?? [], $meta);
        $grant->setRelation('resource', $resource);

        // A revoked grant stays revoked. Someone withdrew this access on
        // purpose, and a form submission is not the place to overturn that —
        // it would let anyone who knows the address undo a moderation
        // decision. Reinstating is a Control Panel action.
        if ($grant->state === GrantState::REVOKED) {
            $grant->save();

            ResourceRequested::dispatch($grant);

            return $grant;
        }

        if (! $resource->requires_confirmation) {
            // No confirmation asked for: the grant is born active. The
            // activation still runs through activate() below when the row
            // already existed, so the confirmed event fires once there too.
            if ($grant->exists && $grant->state === GrantState::ACTIVE) {
                $this->extend($grant, $resource);
                $grant->save();

                ResourceRequested::dispatch($grant);

                return $grant;
            }

            $grant->token_hash = null;
            $grant->state = GrantState::PENDING;
            $this->extend($grant, $resource);
            $grant->save();

            ResourceRequested::dispatch($grant);

            $this->activate($grant);

            return $grant->refresh()->setRelation('resource', $resource);
        }

        if ($grant->state === GrantState::ACTIVE && ! $grant->hasLapsed()) {
            // Already confirmed. Nothing to confirm again — the caller
            // re-sends the delivery mail, which is what the visitor actually
            // wanted, and no second ResourceConfirmed goes out.
            $this->extend($grant, $resource);
            $grant->save();

            ResourceRequested::dispatch($grant);

            return $grant;
        }

        $token = ConfirmationToken::mint();

        $grant->state = GrantState::PENDING;
        $grant->token_hash = ConfirmationToken::hash($token);
        $grant->confirmed_at = null;
        $this->extend($grant, $resource);
        $grant->save();

        $grant->plainToken = $token;

        ResourceRequested::dispatch($grant);

        return $grant;
    }

    /**
     * Find the pending grant a confirmation token addresses.
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
            ->with('resource')
            ->where('token_hash', ConfirmationToken::hash($token))
            ->first();
    }

    /**
     * pending -> active, exactly once.
     *
     * The guarantee is the `where state = pending` on the UPDATE, not the
     * caller's care. Two confirmations arriving at the same instant both read
     * a pending row; only one UPDATE reports a changed row, and only that one
     * fires ResourceConfirmed and returns true. The loser sees the confirmed
     * page and sends no second mail.
     *
     * This is the idempotency the spec asks for, and it holds against a
     * double-clicked link, a mail scanner prefetching the URL, a queue retry
     * and two web workers at once — because it is one statement in the
     * database, not a check-then-act in PHP.
     */
    public function activate(Grant $grant): bool
    {
        $confirmedAt = Carbon::now();

        $changed = Grant::query()
            ->whereKey($grant->getKey())
            ->where('state', GrantState::PENDING)
            ->update([
                'state' => GrantState::ACTIVE,
                'confirmed_at' => $confirmedAt,
                'token_hash' => null,
                'updated_at' => $confirmedAt,
            ]);

        if ($changed !== 1) {
            return false;
        }

        $grant->forceFill([
            'state' => GrantState::ACTIVE,
            'confirmed_at' => $confirmedAt,
            'token_hash' => null,
        ])->syncOriginal();

        ResourceConfirmed::dispatch($grant);

        return true;
    }

    /** Withdraw access. Terminal: only the Control Panel can undo it. */
    public function revoke(Grant $grant): Grant
    {
        $grant->forceFill([
            'state' => GrantState::REVOKED,
            'revoked_at' => Carbon::now(),
            'token_hash' => null,
        ])->save();

        return $grant;
    }

    /** Put a revoked or expired grant back into service, without re-confirming. */
    public function reinstate(Grant $grant): Grant
    {
        $grant->forceFill([
            'state' => GrantState::ACTIVE,
            'revoked_at' => null,
            'confirmed_at' => $grant->confirmed_at ?? Carbon::now(),
        ]);

        if ($grant->hasLapsed()) {
            $this->extend($grant, $grant->resource);
        }

        $grant->save();

        return $grant;
    }

    /**
     * Write the `expired` state onto rows whose date has passed.
     *
     * Housekeeping, not a gate. Nothing depends on this having run —
     * Grant::isRedeemable() reads the date, not the column — so a site that
     * never schedules the sweep is safe, only untidy.
     */
    public function sweepExpired(): int
    {
        return Grant::query()
            ->whereIn('state', [GrantState::PENDING, GrantState::ACTIVE])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now())
            ->update(['state' => GrantState::EXPIRED, 'token_hash' => null]);
    }

    /**
     * Set the grant's own lifetime.
     *
     * A pending grant lives for the confirmation window; once active it lives
     * for the resource's grant lifetime, or forever when none is set. The two
     * are different clocks on purpose: "you have three days to confirm" and
     * "your access lasts a year" are different promises.
     */
    protected function extend(Grant $grant, ?Resource $resource): void
    {
        if ($grant->state === GrantState::ACTIVE) {
            $days = $resource?->grantTtlDays();
            $grant->expires_at = $days === null ? null : Carbon::now()->addDays($days);

            return;
        }

        $hours = (int) config('lead-magnets.requests.confirmation_ttl_hours', 72);

        $grant->expires_at = $hours > 0 ? Carbon::now()->addHours($hours) : null;
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
    public function recordDownload(Grant $grant, array $context = []): \Goldnead\LeadMagnets\Models\Download
    {
        return DB::transaction(function () use ($grant, $context) {
            Grant::query()->whereKey($grant->getKey())->increment('download_count');

            $grant->download_count++;

            return $grant->downloads()->create([
                'brand_id' => $grant->brand_id,
                'downloaded_at' => Carbon::now(),
                'ip_hash' => isset($context['ip']) && $context['ip'] !== null
                    ? hash('sha256', (string) $context['ip'])
                    : null,
                'user_agent' => isset($context['user_agent'])
                    ? mb_substr((string) $context['user_agent'], 0, 255)
                    : null,
            ]);
        });
    }
}
