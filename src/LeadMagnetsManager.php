<?php

namespace Goldnead\LeadMagnets;

use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Services\DeliveryService;
use Goldnead\LeadMagnets\Services\DownloadLink;
use Goldnead\LeadMagnets\Services\GrantService;
use Goldnead\LeadMagnets\Support\EmailNormalizer;

/**
 * The public API, behind the `LeadMagnets` facade.
 *
 * Semver-locked from the first release, so it is deliberately small: request,
 * confirm, deliver, look up. Anything a host application needs beyond this is
 * a case for an event listener, not a new method here.
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
     * pending and the confirmation mail has gone out; when it does not, the
     * grant is already active and the delivery mail has gone out. Either way
     * the caller has one object to look at and `state` tells the story.
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

        if ($grant->isRedeemable()) {
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

        if ($grant->hasLapsed() && $grant->isPending()) {
            return $grant;
        }

        if ($this->grants->activate($grant)) {
            $this->delivery->deliver($grant->refresh()->load('resource'));
        }

        return $grant;
    }

    public function findGrant(Resource $resource, string $email): ?Grant
    {
        return Grant::query()
            ->where('resource_id', $resource->id)
            ->where('email', EmailNormalizer::normalize($email))
            ->first();
    }

    public function downloadUrl(Grant $grant): string
    {
        return $this->links->for($grant);
    }

    public function revoke(Grant $grant): Grant
    {
        return $this->grants->revoke($grant);
    }

    public function reinstate(Grant $grant): Grant
    {
        return $this->grants->reinstate($grant);
    }
}
