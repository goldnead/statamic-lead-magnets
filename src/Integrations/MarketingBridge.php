<?php

namespace Goldnead\LeadMagnets\Integrations;

use Goldnead\LeadMagnets\Models\Grant;

/**
 * Optional: goldnead/statamic-marketing.
 *
 * When a resource names a mailing list and the address has confirmed, the
 * address is subscribed to it.
 *
 * Note what this bridge does **not** do. It does not borrow marketing's
 * double-opt-in for the resource itself: the confirmation this addon sends is
 * consent to receive one file, and a mailing-list subscription is a separate
 * permission that a resource request may not silently grant. A resource
 * subscribes only when an editor named a list on it, and the subscription
 * then runs marketing's own consent path — including marketing's own
 * confirmation, if that list asks for one.
 *
 * marketing itself is not modified by this package. The coupling is one
 * direction, through marketing's public service, and this addon's whole flow
 * works with marketing absent.
 */
class MarketingBridge extends Bridge
{
    /** @var class-string */
    protected const SERVICE = \Goldnead\Marketing\Services\SubscriptionService::class;

    /** @var class-string */
    protected const REPOSITORY = \Goldnead\Marketing\Contracts\Repositories\MailingListRepository::class;

    public function available(): bool
    {
        return $this->enabled('marketing')
            && class_exists(self::SERVICE)
            && interface_exists(self::REPOSITORY);
    }

    public function onActivated(Grant $grant): void
    {
        $handle = $grant->resource?->marketing_list;

        if (! $handle || ! $this->available()) {
            return;
        }

        $this->attempt('marketing subscription ['.$handle.']', function () use ($grant, $handle) {
            $lists = app(self::REPOSITORY);
            $list = $lists->find($handle);

            if (! $list) {
                return null;
            }

            $service = app(self::SERVICE);

            if (! method_exists($service, 'subscribe')) {
                return null;
            }

            return $service->subscribe(
                $list,
                $grant->email,
                [],
                ['source' => 'lead-magnets', 'resource' => $grant->resource?->handle],
            );
        });
    }
}
