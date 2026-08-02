<?php

namespace Goldnead\LeadMagnets\Integrations;

use Goldnead\Suppression\Facades\SuppressionGate;
use Illuminate\Support\Facades\Log;

/**
 * Optional: goldnead/statamic-suppression.
 *
 * One question — may this address be mailed? — asked before every send.
 *
 * Fails **open** when the addon is absent, and closed only on a real "yes,
 * suppressed". That direction is deliberate: without the addon installed
 * there is no suppression list, so refusing everything would mean an addon
 * that delivers nothing until an optional package is installed. With the
 * addon installed and throwing, the answer is unknown and the send is held —
 * a bounce list that errors is not permission to mail a complainant.
 */
class SuppressionBridge extends Bridge
{
    /** @return class-string */
    protected function facade(): string
    {
        return SuppressionGate::class;
    }

    public function available(): bool
    {
        return $this->enabled('suppression') && class_exists($this->facade());
    }

    public function blocks(string $email): bool
    {
        if (! $this->available()) {
            return false;
        }

        $facade = $this->facade();

        if (! $this->rootHas($facade, 'isSuppressed')) {
            return false;
        }

        try {
            return (bool) $facade::isSuppressed($email);
        } catch (\Throwable $e) {
            Log::warning(
                '[lead-magnets] suppression check failed, holding the send: '.$e->getMessage()
            );

            return true;
        }
    }
}
