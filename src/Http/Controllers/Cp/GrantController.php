<?php

namespace Goldnead\LeadMagnets\Http\Controllers\Cp;

use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Services\DeliveryService;
use Goldnead\LeadMagnets\Services\GrantService;
use Illuminate\Http\Request;
use Statamic\Facades\User;

/**
 * The three things an editor does to an existing grant.
 *
 * All writes, all behind `manage lead magnet grants` — a separate permission
 * from `manage lead magnets` on purpose: authoring resources and reaching into
 * a named person's access are different jobs.
 */
class GrantController extends Controller
{
    /**
     * Withdraw access.
     *
     * Entitlements refuses a revocation without a reason, and it is right to:
     * "revoked, reason: (blank)" six months later gets overturned by whoever is
     * on support that day. An editor may type one; when they do not, the fallback
     * still records what actually happened and who did it, which is more than a
     * blank ever would.
     */
    public function revoke(Request $request, int $grant, GrantService $grants)
    {
        $this->authorizeOrFail($request, 'manage lead magnet grants');

        $record = Grant::query()->with('entitlement')->find($grant);
        abort_if($record === null, 404);

        // `validate()` omits a nullable key that was not sent at all, so this
        // reads with `??` rather than indexing straight into the result.
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $reason = trim((string) ($validated['reason'] ?? ''));

        if ($reason === '') {
            $reason = __('lead-magnets::grants.revoked_reason_default', [
                'user' => (string) (User::current()?->email() ?? '—'),
            ]);
        }

        $grants->revoke($record, $reason);

        return back()->with('success', __('lead-magnets::grants.revoked'));
    }

    public function reinstate(Request $request, int $grant, GrantService $grants)
    {
        $this->authorizeOrFail($request, 'manage lead magnet grants');

        $record = Grant::query()->with(['resource', 'entitlement'])->find($grant);
        abort_if($record === null, 404);

        $grants->reinstate($record);

        return back()->with('success', __('lead-magnets::grants.reinstated'));
    }

    /**
     * Send the delivery mail again, with a fresh signed link.
     *
     * Not a re-confirmation: the address is already proven, and asking someone
     * to confirm twice because their first link expired is a support problem
     * dressed up as diligence. Only an active grant qualifies, which is what
     * keeps this from becoming a way to mail an unconfirmed address.
     */
    public function resend(Request $request, int $grant, DeliveryService $delivery)
    {
        $this->authorizeOrFail($request, 'manage lead magnet grants');

        $record = Grant::query()->with(['resource', 'entitlement'])->find($grant);
        abort_if($record === null, 404);

        if (! $delivery->deliver($record)) {
            return back()->withErrors(['grant' => __('lead-magnets::grants.resend_refused')]);
        }

        return back()->with('success', __('lead-magnets::grants.resent'));
    }
}
