<?php

namespace Goldnead\LeadMagnets\Integrations;

use Goldnead\LeadMagnets\Events\GrantEvent;
use Goldnead\LeadMagnets\Events\ResourceConfirmed;
use Goldnead\LeadMagnets\Integrations\ActivityBridge as Activity;
use Goldnead\LeadMagnets\Models\Grant;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Wires the optional bridges to this addon's events, once.
 *
 * Why this is not simply done in `bootAddon()`
 * -------------------------------------------
 * Statamic calls `bootAddon()` from inside an `app->booted()` callback of its
 * own. A sibling addon's provider may not have booted at that point, so
 * anything that touches a sibling's container binding from `bootAddon()` runs
 * too early and finds nothing. Nesting another `booted()` inside it does not
 * help either — a callback queued while the application is already booting
 * fires immediately.
 *
 * The pattern that does work, and that marketing and leadhub both carry: queue
 * from `boot()`, and queue a second pass from inside the first. The first pass
 * catches the common case; the second runs after every provider registered in
 * the meantime, which is when a late sibling appears. Both passes are the same
 * closure, and `$booted` makes the second one free.
 *
 * The cost of getting this wrong is not an exception. It is silence: leadhub
 * once lost all fourteen of its trigger registrations this way, and every test
 * still passed, because "no listener registered" and "listener registered and
 * nothing happened" look identical from the outside. `tests/Feature/
 * BridgeBootOrderTest.php` asserts the registration itself for that reason.
 */
class SiblingBridges
{
    protected bool $booted = false;

    public function __construct(
        protected LeadhubBridge $leadhub,
        protected MarketingBridge $marketing,
        protected Activity $activity,
    ) {}

    public function boot(Dispatcher $events): void
    {
        if ($this->booted) {
            return;
        }

        // Nothing installed, nothing to wire — but do not mark it booted. A
        // second pass may still find a sibling that had not registered yet.
        if (! $this->anyAvailable()) {
            return;
        }

        $this->booted = true;

        $events->listen(ResourceConfirmed::class, function (ResourceConfirmed $event): void {
            $this->onConfirmed($event->grant);
        });

        foreach (array_keys(Activity::EVENT_TYPES) as $eventClass) {
            $events->listen($eventClass, function (GrantEvent $event): void {
                $this->activity->record($event);
            });
        }
    }

    public function booted(): bool
    {
        return $this->booted;
    }

    protected function anyAvailable(): bool
    {
        return $this->leadhub->available()
            || $this->marketing->available()
            || $this->activity->available();
    }

    /**
     * Everything that happens to the outside world when a grant activates.
     *
     * Ordered: the contact first, because the tags are written onto it, and
     * the mailing-list subscription last, because it is the only step that may
     * itself start a second consent flow.
     */
    protected function onConfirmed(Grant $grant): void
    {
        if ($contactId = $this->leadhub->onActivated($grant)) {
            $grant->forceFill(['contact_id' => $contactId])->save();
        }

        $this->marketing->onActivated($grant);
    }
}
