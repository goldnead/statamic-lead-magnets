<?php

namespace Goldnead\LeadMagnets\Integrations;

use Goldnead\LeadMagnets\Events\GrantEvent;
use Goldnead\LeadMagnets\Events\ResourceConfirmed;
use Goldnead\LeadMagnets\Events\ResourceDelivered;
use Goldnead\LeadMagnets\Events\ResourceDownloaded;
use Goldnead\LeadMagnets\Events\ResourceRequested;

/**
 * Optional: goldnead/statamic-activity.
 *
 * Writes the four domain events onto the shared ledger, so a resource request
 * shows up on the same timeline as a form submission and a campaign click.
 */
class ActivityBridge extends Bridge
{
    /** @var class-string */
    protected const FACADE = \Goldnead\Activity\Facades\Activity::class;

    /** @var array<class-string<GrantEvent>, string> */
    public const EVENT_TYPES = [
        ResourceRequested::class => 'lead-magnets.resource.requested',
        ResourceConfirmed::class => 'lead-magnets.resource.confirmed',
        ResourceDelivered::class => 'lead-magnets.resource.delivered',
        ResourceDownloaded::class => 'lead-magnets.resource.downloaded',
    ];

    public function available(): bool
    {
        return $this->enabled('activity') && class_exists(self::FACADE);
    }

    public function record(GrantEvent $event): void
    {
        if (! $this->available()) {
            return;
        }

        $type = self::EVENT_TYPES[$event::class] ?? null;

        if ($type === null) {
            return;
        }

        $facade = self::FACADE;

        if (! $this->rootHas($facade, 'record')) {
            return;
        }

        $this->attempt('activity record ['.$type.']', fn () => $facade::record($type, [
            'subject_type' => $event->grant::class,
            'subject_id' => $event->grant->getKey(),
            'email' => $event->grant->email,
            'properties' => $event->payload(),
        ]));
    }
}
