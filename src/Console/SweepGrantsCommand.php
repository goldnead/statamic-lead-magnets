<?php

namespace Goldnead\LeadMagnets\Console;

use Goldnead\LeadMagnets\Services\GrantService;
use Illuminate\Console\Command;

/**
 * Marks lapsed grants `expired`.
 *
 * Housekeeping only. No access decision depends on this having run: the
 * download gate reads `expires_at`, not the state column, so a site that never
 * schedules this is safe and merely has a Control Panel listing that overstates
 * how many grants are live.
 */
class SweepGrantsCommand extends Command
{
    protected $signature = 'lead-magnets:sweep';

    protected $description = 'Mark lead-magnet grants whose lifetime has passed as expired';

    public function handle(GrantService $grants): int
    {
        $count = $grants->sweepExpired();

        $this->info(__('lead-magnets::console.swept', ['count' => $count]));

        return self::SUCCESS;
    }
}
