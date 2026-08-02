<?php

namespace Goldnead\LeadMagnets;

use Goldnead\LeadMagnets\Console\SweepGrantsCommand;
use Goldnead\LeadMagnets\Integrations\SiblingBridges;
use Illuminate\Console\Scheduling\Schedule;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
        'web' => __DIR__.'/../routes/web.php',
    ];

    // Registered by hand in register() under the exact `lead-magnets`
    // namespace, plus the JSON path the Vue layer's `__('Some sentence')`
    // calls resolve through. The parent's automatic registration covers only
    // the first of those two.
    protected $translations = false;

    protected $config = true;

    /**
     * Statamic 6 reads the addon's Vite configuration from THIS property.
     *
     * Not from `extra.statamic.vite` in composer.json — that key is read by the
     * v5 provider and is silently ignored in v6, which produces an addon whose
     * Control Panel screens load with no JavaScript and no styles at all, with
     * nothing in the log. The three values below must byte-match `laravel()`
     * in vite.config.js.
     */
    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    protected $commands = [
        SweepGrantsCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $langPath = __DIR__.'/../resources/lang';

        $this->app->resolving('translator', function ($translator) use ($langPath) {
            $translator->addNamespace('lead-magnets', $langPath);
            $translator->addJsonPath($langPath);
        });

        if ($this->app->resolved('translator')) {
            $this->app['translator']->addNamespace('lead-magnets', $langPath);
            $this->app['translator']->addJsonPath($langPath);
        }

        // Singletons, so the bridges' boot guards hold across resolutions.
        // A per-resolution bridge would re-register its listeners on every
        // container make and fire each event as many times as it was resolved.
        $this->app->singleton(SiblingBridges::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerSiblingBridges();
    }

    public function bootAddon(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'lead-magnets');

        $this
            ->bootNav()
            ->bootPermissions()
            ->bootSchedule()
            ->bootPublishables();
    }

    /**
     * Boot the optional sibling bridges after every provider has booted.
     *
     * Queued from `boot()`, not from `bootAddon()`. Statamic calls
     * `bootAddon()` from inside an `app->booted()` callback of its own, and a
     * callback queued while the application is already booting fires
     * immediately — before the sibling addons whose bindings the bridges need.
     * The double queueing gives a late-registered sibling a second chance, and
     * `SiblingBridges` is idempotent so the second pass costs nothing.
     */
    protected function registerSiblingBridges(): void
    {
        $boot = function (): void {
            $this->app->make(SiblingBridges::class)->boot($this->app->make('events'));
        };

        $this->app->booted(function () use ($boot): void {
            $boot();

            $this->app->booted($boot);
        });
    }

    protected function bootNav(): self
    {
        Nav::extend(function ($nav) {
            $nav->create(__('lead-magnets::nav.lead_magnets'))
                ->section('Tools')
                ->icon('download')
                ->route('lead-magnets.resources.index')
                ->can('view lead magnets');
        });

        return $this;
    }

    protected function bootPermissions(): self
    {
        Permission::extend(function () {
            Permission::group('lead_magnets', 'Lead Magnets', function () {
                Permission::register('view lead magnets')
                    ->label(__('lead-magnets::permissions.view'))
                    ->children([
                        Permission::make('manage lead magnets')
                            ->label(__('lead-magnets::permissions.manage')),
                        Permission::make('manage lead magnet grants')
                            ->label(__('lead-magnets::permissions.manage_grants')),
                    ]);
            });
        });

        return $this;
    }

    /**
     * `callAfterResolving`, not `app->booted()`.
     *
     * In a Statamic application the booted callbacks fire twice — the bridge
     * registration above leans on that and is idempotent for it. A schedule
     * registration is not idempotent, and marketing measured exactly this:
     * one call, two entries in `schedule:list`. Binding to the Schedule
     * singleton instead runs the callback once, when it is resolved, however
     * often the application announces that it has booted.
     */
    protected function bootSchedule(): self
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('lead-magnets:sweep')
                ->hourly()
                ->onOneServer()
                ->name('lead-magnets-sweep');
        });

        return $this;
    }

    protected function bootPublishables(): self
    {
        $this->publishes([
            __DIR__.'/../config/lead-magnets.php' => config_path('lead-magnets.php'),
        ], 'lead-magnets-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/lead-magnets'),
        ], 'lead-magnets-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/lead-magnets'),
        ], 'lead-magnets-translations');

        // Merged as well as published. A config that is published but never
        // merged returns null on every site that did not publish it, which
        // breaks the addon precisely for the users who did nothing wrong.
        $this->mergeConfigFrom(__DIR__.'/../config/lead-magnets.php', 'lead-magnets');

        return $this;
    }
}
