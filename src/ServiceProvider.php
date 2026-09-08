<?php

namespace Goldnead\LeadMagnets;

use Goldnead\BrandContext\Settings\SettingsRegistry;
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
use Goldnead\LeadMagnets\Support\Settings;
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

        // Die eigene Config zusammenfuehren, und zwar hier und nicht im Boot.
        //
        // `brand-context` haelt sich beim ersten Anwenden die Paketwerte als
        // Baseline fest (`SettingsManager::baselineFor()`), und das laeuft aus
        // `app->booted()`. Statamic ruft `bootAddon()` aus einem *eigenen*
        // `app->booted()`-Rueckruf, der spaeter dran ist. Solange das Merge
        // dort hing, war `config('lead-magnets')` im Moment der Baseline noch
        // leer — und `??=` friert diese Leere fuer den Rest des Prozesses ein.
        //
        // Was das kostet: `packagedDefault()` antwortet dann fuer jeden
        // Schluessel mit `null`, kein gespeicherter Wert entspricht je seinem
        // Paket-Default, und die Zeile in `brand_settings` wird nie geloescht.
        // Jede Einstellung bleibt auf ihrem Wert festgenagelt und die
        // Installation gegen kuenftige Paket-Updates eingefroren, ohne Fehler
        // und ohne Meldung. Gefallen ist das an
        // `SettingsEditorTest > deletes the override when a value goes back to
        // the packaged default`, ab brand-context 1.13.0.
        //
        // In `register()` ist ausserdem die Stelle, an der Laravel das Merge
        // ohnehin erwartet, und an der es die siebzehn Geschwister-Addons der
        // Suite auch machen. Nur `publishes()` bleibt im Boot: das braucht die
        // Pfad-Helfer der Anwendung.
        $this->mergeConfigFrom(__DIR__.'/../config/lead-magnets.php', 'lead-magnets');

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

    /**
     * Define the disk the addon's asset container sits on, unless the host
     * application already defines one under that name.
     *
     * Its own disk, and emphatically not one of the disks already there. The
     * container config of Statamic's `assets` fieldtype defaults to the site's
     * single existing container, which on a standard install sits on the
     * `assets` disk: `public/assets`, with a URL, served by the web server
     * before Laravel sees the request. Putting a resource that is gated behind
     * a double opt-in there publishes it at a guessable address, and nothing
     * in the Control Panel would say so.
     *
     * So: no `url`, no `serve`, no public visibility, and a root under
     * `storage/app` rather than anywhere below the document root. The signed
     * download route is then the only way to the file, which is the whole
     * point of the addon.
     *
     * Registered here rather than published into the host's
     * `config/filesystems.php`, so that installing the addon is enough and
     * there is no step between "composer require" and a safe container.
     *
     * In the boot phase, not in `register()`. Die eigene Config waere seit dem
     * Vorziehen des Merges nach `register()` zwar schon lesbar, aber der
     * Disk-Eintrag gehoert trotzdem hierher: Laravel entscheidet die
     * `/storage`-Routen in `FilesystemServiceProvider::register()`, und ein
     * frueher eingehaengter Disk wuerde dort mitgeroutet werden. Spaet ist
     * harmlos — ein Disk wird erst gebaut, wenn ihn jemand anfragt.
     */
    protected function bootAssetDisk(): self
    {
        $disk = (string) config('lead-magnets.assets.disk', 'lead-magnets');

        if ($disk === '' || is_array(config('filesystems.disks.'.$disk))) {
            return $this;
        }

        config(['filesystems.disks.'.$disk => [
            'driver' => 'local',
            'root' => storage_path('app/lead-magnets'),
            'serve' => false,
            'throw' => false,
        ]]);

        return $this;
    }

    public function boot(): void
    {
        parent::boot();

        // Diesem Addon seinen Abschnitt auf der gemeinsamen Einstellungsseite
        // geben. In `boot()`, nicht in `bootAddon()`, und das ist keine
        // Stilfrage: brand-context legt die gespeicherten Werte aus einem
        // `app->booted()`-Rueckruf auf die laufende Config, damit jedes Addon
        // vorher seine Chance zum Anmelden hatte. `bootAddon()` laeuft
        // selbst aus einem `app->booted()`-Rueckruf, und welcher der beiden
        // zuerst dran ist, haengt an der Paket-Ladereihenfolge — dort
        // angemeldet wuerden die Einstellungen auf manchen Installationen
        // greifen und auf anderen nicht.
        app(SettingsRegistry::class)->register(Settings::class);

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
            ->bootPublishables()
            ->bootAssetDisk();
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
                        // Der Abschnitt auf der gemeinsamen Einstellungsseite
                        // (`/cp/brand-settings`). Nach der Regel der Suite
                        // benannt — `manage <handle> settings` mit dem
                        // Paketnamen ohne `statamic-`-Praefix —, damit die
                        // Seite ueber alle Addons hinweg eine Namensform hat.
                        Permission::make('manage lead-magnets settings')
                            ->label(__('lead-magnets::permissions.manage_settings')),
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
        // breaks the addon precisely for the users who did nothing wrong. Das
        // Zusammenfuehren steht in `register()`, weil es dort frueh genug ist
        // fuer die Baseline von brand-context; hier bleibt nur das
        // Veroeffentlichen.

        return $this;
    }
}
