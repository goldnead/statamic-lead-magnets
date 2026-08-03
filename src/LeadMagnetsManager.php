<?php

namespace Goldnead\LeadMagnets;

use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Services\DeliveryService;
use Goldnead\LeadMagnets\Services\DownloadLink;
use Goldnead\LeadMagnets\Services\GrantService;
use Goldnead\LeadMagnets\Support\EmailNormalizer;

/**
 * The public API, behind the `LeadMagnets` facade.
 *
 * Semver-locked, so it is deliberately small: request, confirm, deliver, look
 * up. Anything a host application needs beyond this is a case for an event
 * listener, not a new method here.
 *
 * Access questions are not asked here. `Entitlements::allows()` and
 * `Entitlements::decide()` answer them for every addon on the platform, and a
 * second façade over the same data would be the duplication this package just
 * spent a major version removing.
 */
class LeadMagnetsManager
{
    public function __construct(
        protected GrantService $grants,
        protected DeliveryService $delivery,
        protected DownloadLink $links,
    ) {}

    public function resource(string $handle): ?Resource
    {
        return Resource::query()->where('handle', $handle)->first();
    }

    /**
     * Ask for a resource on someone's behalf.
     *
     * Returns the grant. When the resource wants a confirmation the grant is
     * pending and the confirmation mail has gone out; when it does not, it is
     * already active and the delivery mail has gone out. Either way the caller
     * has one object to look at and `state()` tells the story.
     *
     * @param  array<string, mixed>  $meta
     */
    public function request(Resource $resource, string $email, array $meta = []): Grant
    {
        $grant = $this->grants->request($resource, $email, $meta);

        if ($grant->isPending() && $grant->plainToken !== null) {
            $this->delivery->sendConfirmation($grant);

            return $grant;
        }

        // A repeat request for access that already stands: re-send the file,
        // because that is what the visitor filled the form in for.
        //
        // Not when this call was the one that activated the grant. Delivery for
        // a first activation happens on the entitlements event, and sending
        // here as well would put two identical mails in the same inbox.
        if (! $grant->justActivated && $grant->isRedeemable()) {
            $this->delivery->deliver($grant);
        }

        return $grant;
    }

    /**
     * Redeem a confirmation token.
     *
     * Returns the grant for any known token, so the caller can render the
     * same confirmation page for a second click. Whether *this* call was the
     * one that activated it — and therefore the one that sends the file — is
     * decided inside, once.
     */
    public function confirm(string $token): ?Grant
    {
        $grant = $this->grants->findByToken($token);

        if (! $grant) {
            return null;
        }

        if ($grant->isPending() && $grant->confirmationLapsed()) {
            return $grant;
        }

        $this->grants->activate($grant);

        return $grant->refresh();
    }

    public function findGrant(Resource $resource, string $email): ?Grant
    {
        return Grant::query()
            ->with('entitlement')
            ->where('resource_id', $resource->id)
            ->where('email', EmailNormalizer::normalize($email))
            ->first();
    }

    /** The entitlement that decides this grant's access, if it has one yet. */
    public function entitlementFor(Grant $grant): ?Entitlement
    {
        return $grant->entitlement;
    }

    public function downloadUrl(Grant $grant): string
    {
        return $this->links->for($grant);
    }

    /**
     * Withdraw access.
     *
     * The reason is required, and that is entitlements' rule rather than this
     * addon's: a revocation nobody can explain later gets undone by whoever is
     * on support that day. It is the one signature in this API that changed in
     * 2.0.
     */
    public function revoke(Grant $grant, string $reason): Grant
    {
        $this->grants->revoke($grant, $reason);

        return $grant->refresh();
    }

    public function reinstate(Grant $grant): Grant
    {
        return $this->grants->reinstate($grant);
    }
}
