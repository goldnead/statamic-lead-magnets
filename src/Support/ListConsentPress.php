<?php

namespace Goldnead\LeadMagnets\Support;

/**
 * The button press on a coupled confirmation, for exactly as long as the
 * activation it triggers is running.
 *
 * Held in memory, not on the grant. A stamp written to the grant before
 * activation would outlive an activation that failed or lost the race, and a
 * later editor reinstating that still-pending grant would then read it as the
 * reader's consent. Held here, it reaches the marketing bridge only inside the
 * activation the press caused, and only the winning claim fires the event the
 * bridge listens to. `LeadMagnetsManager::confirm()` writes `confirmed_at` onto
 * the grant afterwards, and only when the claim was won.
 */
class ListConsentPress
{
    /** @var array<int, string> grant id => ISO-8601 time of the press */
    protected array $held = [];

    public function hold(int $grantId, string $pressedAt): void
    {
        $this->held[$grantId] = $pressedAt;
    }

    public function heldFor(int $grantId): ?string
    {
        return $this->held[$grantId] ?? null;
    }

    public function release(int $grantId): void
    {
        unset($this->held[$grantId]);
    }
}
