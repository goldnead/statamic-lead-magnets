<?php

namespace Goldnead\LeadMagnets\Integrations;

use Goldnead\StatamicAutomations\Facades\Automations;

/**
 * Optional: goldnead/statamic-automations.
 *
 * One question: does a mail series take over the delivery mail for this
 * address? Asked before access is granted, because granting access is what
 * starts the series, and afterwards there would always be a run.
 *
 * Fails to **false**: without the addon, with the series switched off, or with
 * an automations version that cannot answer, the normal delivery mail goes out.
 * Nobody is left without a mail after confirming.
 */
class AutomationsBridge extends Bridge
{
    /** @return class-string */
    protected function facade(): string
    {
        return Automations::class;
    }

    public function available(): bool
    {
        return $this->enabled('automations') && class_exists($this->facade());
    }

    /** Whether the series is switched on, or null when that cannot be told. */
    public function isLive(string $handle): ?bool
    {
        if ($handle === '' || ! $this->available() || ! $this->rootHas($this->facade(), 'seriesIsLive')) {
            return null;
        }

        $facade = $this->facade();

        return $this->attempt('automations seriesIsLive ['.$handle.']', fn () => (bool) $facade::seriesIsLive($handle));
    }

    /** Whether the series runs for this address now: live, and never run for it before. */
    public function takesOver(string $handle, string $email): bool
    {
        if ($this->isLive($handle) !== true || ! $this->rootHas($this->facade(), 'seriesHasRun')) {
            return false;
        }

        $facade = $this->facade();

        // Unknown history counts as "had it": a second series run is the worse
        // mistake, and the delivery mail is always safe to send.
        $had = $this->attempt('automations seriesHasRun ['.$handle.']', fn () => (bool) $facade::seriesHasRun($handle, $email));

        return $had === false;
    }
}
