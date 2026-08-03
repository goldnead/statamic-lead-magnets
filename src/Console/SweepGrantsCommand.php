<?php

namespace Goldnead\LeadMagnets\Console;

use Goldnead\LeadMagnets\Services\GrantService;
use Illuminate\Console\Command;

/**
 * Clears confirmation secrets whose window has closed.
 *
 * All that is left of the 1.x sweep, and the shrinking is the point. Expiry of
 * *access* used to need a pass like this, because `state` was a column somebody
 * had to write; entitlements derives it from the clock, so there is nothing to
 * mark and nothing that can go stale between runs.
 *
 * What still ages is the token. A hash that can no longer be redeemed has no
 * reason to sit in the database, and clearing it means a leaked backup carries
 * fewer secrets that ever meant anything.
 *
 * Housekeeping only. No access decision depends on this having run.
 *
 * Note for the host application: entitlements announces its own clock-driven
 * transitions through `entitlements:announce`, which is what fires
 * `EntitlementExpired`. Scheduling that is the application's job, not this
 * addon's — it is shared across every consumer of the package.
 */
class SweepGrantsCommand extends Command
{
    protected $signature = 'lead-magnets:sweep';

    protected $description = 'Clear lead-magnet confirmation tokens whose window has closed';

    public function handle(GrantService $grants): int
    {
        $count = $grants->sweepExpiredTokens();

        $this->info(__('lead-magnets::console.swept', ['count' => $count]));

        return self::SUCCESS;
    }
}
