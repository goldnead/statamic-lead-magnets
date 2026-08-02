<?php

namespace Goldnead\LeadMagnets\Integrations;

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
    /** @var class-string */
    protected const FACADE = \Goldnead\Suppression\Facades\SuppressionGate::class;

    public function available(): bool
    {
        return $this->enabled('suppression') && class_exists(self::FACADE);
    }

    public function blocks(string $email): bool
    {
        if (! $this->available()) {
            return false;
        }

        $facade = self::FACADE;

        if (! $this->rootHas($facade, 'isSuppressed')) {
            return false;
        }

        try {
            return (bool) $facade::isSuppressed($email);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                '[lead-magnets] suppression check failed, holding the send: '.$e->getMessage()
            );

            return true;
        }
    }
}
