<?php

namespace Goldnead\LeadMagnets;

use Goldnead\LeadMagnets\Console\MigrateGrantsCommand;
use Goldnead\LeadMagnets\Console\SweepGrantsCommand;
use Goldnead\LeadMagnets\Contracts\SenderIdentityResolver;
use Goldnead\LeadMagnets\Integrations\Insights\Confirmed;
use Goldnead\LeadMagnets\Integrations\Insights\ConfirmRate;
use Goldnead\LeadMagnets\Integrations\Insights\Downloads;
use Goldnead\LeadMagnets\Integrations\Insights\Requested;
use Goldnead\LeadMagnets\Integrations\SiblingBridges;
use Goldnead\LeadMagnets\Sending\BrandMailer;
use Goldnead\LeadMagnets\Sending\BrandSenderIdentity;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;
use Throwable;

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
        MigrateGrantsCommand::class,
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
        // Brand-scoped sending. The contract and the mechanism live in
        // statamic-brand-context; this package binds only its own name. The
        // shipped implementation leaves a single-brand install sending exactly
        // as before.
        $this->app->singleton(SenderIdentityResolver::class, BrandSenderIdentity::class);
        $this->app->singleton(BrandMailer::class);

        $this->app->singleton(SiblingBridges::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerSiblingBridges();
        $this->registerInsightsMetrics();
    }

    /**
     * The metric handles this addon contributes, and the classes behind them.
     *
     * Handle and class both, so the registry can file the class name without
     * building anything to find out what it is called — an installation with
     * twenty addons would otherwise construct every metric of every one of them
     * on a request that renders none.
     *
     * **The handles are frozen from the moment they are registered.** They end
     * up in saved dashboards and in URLs; renaming one is a breaking change.
     *
     * @var array<class-string, string>
     */
    protected const INSIGHTS_METRICS = [
        Requested::class => 'lead_magnets.requested',
        Confirmed::class => 'lead_magnets.confirmed',
        Downloads::class => 'lead_magnets.downloads',
        ConfirmRate::class => 'lead_magnets.confirm_rate',
    ];

    /** Set once the metrics have been handed over, so the second pass stays free. */
    protected bool $insightsRegistered = false;

    /**
     * Offer the four figures to the analytics addon, if it is there.
     *
     * Queued the same way as the sibling bridges above and for the same reason:
     * a callback queued while the application is already booting fires
     * immediately, before the sibling whose facade this asks for has registered
     * anything. The double queueing gives a late-registered sibling a second
     * chance, and the guard makes the second pass free.
     *
     * **Nothing here throws, ever.** A missing, half-installed or mid-upgrade
     * analytics addon must cost a few tiles on a screen nobody has open, never
     * a delivery. The guards are the three that have each caught a real
     * variation of "installed but not quite": the facade class may be absent,
     * the container may refuse to build the manager, and an older release of the
     * sibling may carry the facade without this method on it. The second of
     * those is the one this family learned by hand — never `method_exists()` on
     * a Facade class, which declares none of what it forwards.
     *
     * The metric classes name the sibling's base class in their `extends`,
     * which is safe precisely because of the first guard: PHP loads a class when
     * something touches it, and nothing touches these unless the facade exists.
     * Hence `suggest` in composer.json rather than `require`.
     */
    protected function registerInsightsMetrics(): void
    {
        $attach = function (): void {
            if ($this->insightsRegistered) {
                return;
            }

            $facade = '\\Goldnead\\StatamicInsights\\Facades\\Insights';

            if (! class_exists($facade)) {
                return;
            }

            try {
                $manager = $facade::getFacadeRoot();

                if (! is_object($manager) || ! method_exists($manager, 'registerMetric')) {
                    return;
                }

                foreach (self::INSIGHTS_METRICS as $class => $handle) {
                    $manager->registerMetric($class, $handle);
                }

                $this->insightsRegistered = true;
            } catch (Throwable $e) {
                Log::warning('statamic-lead-magnets: the insights metrics could not be registered.', [
                    'exception' => $e->getMessage(),
                ]);
            }
        };

        $this->app->booted(function () use ($attach): void {
            $attach();

            $this->app->booted($attach);
        });
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
            // Named, and checked against the names already registered.
            //
            // `callAfterResolving` alone is not enough. It fires once per
            // resolution, which is the right shape — but `bootAddon()` itself
            // runs more than once in a Statamic application, so the callback
            // gets *registered* twice and the entry lands twice.
            //
            // Marketing measured the same duplication and survived it by luck:
            // `onOneServer()` with a fixed name means the second copy loses the
            // mutex and is skipped. Luck is not a design, and the next entry
            // added without `onOneServer()` would simply run twice — for a
            // digest, that is two mails to the same person.
            $already = collect($schedule->events())
                ->contains(fn ($event) => $event->description === 'lead-magnets-sweep');

            if ($already) {
                return;
            }

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
