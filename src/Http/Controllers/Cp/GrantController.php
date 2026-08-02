<?php

namespace Goldnead\LeadMagnets\Http\Controllers\Cp;

use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Services\DeliveryService;
use Goldnead\LeadMagnets\Services\GrantService;
use Illuminate\Http\Request;

/**
 * The three things an editor does to an existing grant.
 *
 * All writes, all behind `manage lead magnet grants` — a separate permission
 * from `manage lead magnets` on purpose: authoring resources and reaching into
 * a named person's access are different jobs.
 */
class GrantController extends Controller
{
    public function revoke(Request $request, int $grant, GrantService $grants)
    {
        $this->authorizeOrFail($request, 'manage lead magnet grants');

        $record = Grant::query()->find($grant);
        abort_unless($record, 404);

        $grants->revoke($record);

        return back()->with('success', __('lead-magnets::grants.revoked'));
    }

    public function reinstate(Request $request, int $grant, GrantService $grants)
    {
        $this->authorizeOrFail($request, 'manage lead magnet grants');

        $record = Grant::query()->with('resource')->find($grant);
        abort_unless($record, 404);

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

        $record = Grant::query()->with('resource')->find($grant);
        abort_unless($record, 404);

        if (! $delivery->deliver($record)) {
            return back()->withErrors(['grant' => __('lead-magnets::grants.resend_refused')]);
        }

        return back()->with('success', __('lead-magnets::grants.resent'));
    }
}
