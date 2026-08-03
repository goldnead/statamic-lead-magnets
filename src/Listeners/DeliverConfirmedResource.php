<?php

namespace Goldnead\LeadMagnets\Listeners;

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Events\EntitlementGranted;
use Goldnead\LeadMagnets\Events\ResourceConfirmed;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Services\DeliveryService;
use Goldnead\LeadMagnets\Support\LeadMagnetSubject;

/**
 * Sends the file when an entitlement this addon owns is confirmed.
 *
 * Entitlements decides access and announces it. It sends nothing — no mail, no
 * magic links, no accounts — and that separation is the reason it could be
 * extracted at all. The mail is this addon's job, and this listener is the
 * seam between the two.
 *
 * ## Why the previous state is the trigger
 *
 * `EntitlementGranted` fires for more than one kind of transition, and only one
 * of them means "somebody just confirmed their address":
 *
 *  - `previousState === Pending` — a confirmation was claimed. Send.
 *  - `previousState === null` — a grant written straight to active. That is
 *    what the data migration does for every historical row, and delivering
 *    there would mail the entire back catalogue on upgrade.
 *  - `previousState === Scheduled` — a start date arrived, announced by
 *    `entitlements:announce`. This addon never schedules.
 *  - `previousState === Revoked` — an editor restored access. The reader
 *    already has their link; a restore is not a new delivery.
 *
 * Filtering on the transition rather than on a flag means the migration needs
 * no special mode and no listener to unregister. There is nothing to remember
 * to switch off, which is the only kind of safeguard that survives.
 *
 * ## How this gets registered
 *
 * By Statamic, not by the service provider. `AddonServiceProvider::bootEvents()`
 * scans `src/Listeners`, reads the type hint on `handle()` and binds the two
 * together. Registering it a second time by hand — which is the obvious thing
 * to do, and was done here once — produces two listeners for one event and two
 * identical download links in the reader's inbox. The suite catches it, but
 * only because a test counts the mails rather than asserting one was sent.
 *
 * ## Order
 *
 * `ResourceConfirmed` is dispatched here, before the send, so the sibling
 * bridges still see the contact created and tagged before anything leaves the
 * building — the order 1.x had, preserved through the move.
 */
class DeliverConfirmedResource
{
    public function __construct(protected DeliveryService $delivery) {}

    public function handle(EntitlementGranted $event): void
    {
        if ($event->previousState !== EntitlementState::Pending) {
            return;
        }

        // Other addons and the host application write entitlements too. Ours
        // carry our source, and anything else is none of our business.
        if ($event->entitlement->source !== LeadMagnetSubject::source()) {
            return;
        }

        $grant = Grant::query()
            ->with('resource')
            ->where('entitlement_id', $event->entitlement->getKey())
            ->first();

        if ($grant === null) {
            return;
        }

        $grant->setRelation('entitlement', $event->entitlement);

        // The secret is spent the moment it is redeemed. A token that outlived
        // its confirmation is a replayable activation.
        if ($grant->token_hash !== null || $grant->confirm_expires_at !== null) {
            $grant->forceFill(['token_hash' => null, 'confirm_expires_at' => null])->save();
        }

        ResourceConfirmed::dispatch($grant);

        $this->delivery->deliver($grant);
    }
}
