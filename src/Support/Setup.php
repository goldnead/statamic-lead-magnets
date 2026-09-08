<?php

namespace Goldnead\LeadMagnets\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The check a CP listing runs before its first query.
 *
 * The nav entry appears as soon as the addon is installed, so the resources
 * screen is reachable before anybody has run `php artisan migrate`. It then hit
 * `Resource::query()` against a table that did not exist and answered HTTP 500.
 * A missing table is an operator's unfinished setup, not a bug, and it owes the
 * reader a sentence rather than a stack trace.
 *
 * Both sides of the sum count here. This addon keeps the delivery record and
 * entitlements keeps the access state, so the listing's two numbers are a join
 * across a table this addon does not own. `entitlements` therefore gets named
 * to `guard()` alongside the addon's own — a page that checked only its own
 * tables would still crash on a half-migrated install, and would be the more
 * confusing failure of the two.
 *
 * The reason must not vanish with the 500: every guarded page that turns
 * somebody away writes why to the log first. A page that renders an empty state
 * and says nothing anywhere would be worse than the crash it replaced — the
 * site would look installed and never work.
 */
final class Setup
{
    /**
     * The setup screen for a CP listing, or null when the page can run.
     *
     * @param  string  $title  The page's own heading, so the screen still reads as that page.
     * @param  string  ...$tables  Every table the listing touches while rendering, foreign ones included.
     */
    public static function guard(string $title, string ...$tables): ?Response
    {
        $missing = array_values(array_filter(
            $tables,
            fn (string $table) => ! Schema::hasTable($table)
        ));

        if ($missing === []) {
            return null;
        }

        Log::error(sprintf(
            'statamic-lead-magnets: the CP page "%s" cannot load because these database tables do not exist: %s. Run `php artisan migrate`.',
            $title,
            implode(', ', $missing)
        ));

        return Inertia::render('lead-magnets::SetupRequired', [
            'title' => $title,
            'heading' => __('lead-magnets::resources.setup_required_heading'),
            'description' => __('lead-magnets::resources.setup_required_description'),
            'tables' => $missing,
        ]);
    }
}
