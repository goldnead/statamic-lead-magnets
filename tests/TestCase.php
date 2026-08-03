<?php

namespace Goldnead\LeadMagnets\Tests;

use Goldnead\LeadMagnets\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Statamic\Testing\AddonTestCase;

/**
 * The whole suite runs with NO sibling addon installed.
 *
 * That is not a limitation of the harness, it is the central claim of this
 * package: leadhub, marketing, email-templates, suppression and activity are
 * optional, and the only way to prove it is to never install them. They are
 * absent from `composer.json` entirely — not in `require`, not in
 * `require-dev` — so every request, confirmation and download in these tests
 * happens through this addon's own mail, its own grant state and its own
 * signed route.
 *
 * The bridges are still covered. `tests/Fixtures/` carries stand-ins that are
 * class_alias'd into the sibling namespaces for the handful of tests that need
 * to see a bridge fire, which tests the guard logic without the dependency.
 *
 * `AddonTestCase` rather than a hand-rolled Testbench case: it boots
 * Statamic, Inertia and this provider the way a real install does, including
 * the `Statamic::booted` callback that `bootAddon()` runs inside — and getting
 * that boot order wrong by hand is exactly how a bridge registration
 * disappears silently.
 */
abstract class TestCase extends AddonTestCase
{
    use RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    /**
     * Statamic's file user repository writes a YAML file per user, and
     * `RefreshDatabase` knows nothing about files. Left alone they accumulate
     * across the whole run, so the tenth test finds nine strangers already
     * signed up — and the permission sweeps then measure the wrong install.
     */
    protected function tearDown(): void
    {
        foreach (glob(__DIR__.'/__fixtures__/users/*.yaml') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            \Goldnead\BrandContext\ServiceProvider::class,
            \Goldnead\IdentityContracts\ServiceProvider::class,
            \Goldnead\Entitlements\ServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    /**
     * Load the entitlements migrations by hand.
     *
     * `AddonTestCase` builds a Statamic addon manifest containing exactly one
     * entry — the addon under test — and `AddonServiceProvider::boot()` returns
     * early for any provider that is not in it. Entitlements is a Composer
     * dependency here, not the addon under test, so its `bootAddon()` never
     * runs and its migrations are never registered. The first query against the
     * table then fails with "no such table", which reads as a bug in this
     * package rather than as a missing boot.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(
            dirname((new \ReflectionClass(\Goldnead\Entitlements\ServiceProvider::class))->getFileName())
            .'/../database/migrations'
        );
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->testingConnection());

        $app['config']->set('statamic.users.repository', 'file');

        // Free Statamic allows exactly one user, and the CP authorization
        // sweep needs at least two (one with the permission, one without).
        // Nothing this addon does depends on Pro; this is a fixture concern.
        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.from', ['address' => 'noreply@example.com', 'name' => 'Test']);

        $app['config']->set('filesystems.disks.lead-magnets', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/lead-magnets'),
            'throw' => false,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Set after boot, not in defineEnvironment().
        //
        // `mergeConfigFrom()` is a shallow `array_merge`, so a
        // `lead-magnets.delivery.disk` written before the provider registers
        // replaces the package's whole `delivery` array — link_ttl,
        // max_downloads and grant_ttl_days all become null, and the suite then
        // measures an addon nobody will ever install. Writing after boot
        // touches the one key and leaves the merged defaults alone.
        config()->set('lead-magnets.delivery.disk', 'lead-magnets');

        // The throttle would otherwise turn the eleventh request in a test
        // class into a 429 that has nothing to do with what is under test.
        config()->set('lead-magnets.requests.throttle', '10000,1');
    }

    /**
     * In-memory SQLite by default; `DB_DRIVER=mysql` points the identical suite
     * at a real server. SQLite has no InnoDB key limit and no utf8mb4 byte
     * arithmetic, which is exactly how a fully green suite in
     * statamic-notifications let an unbuildable index reach production.
     *
     * @return array<string, mixed>
     */
    protected function testingConnection(): array
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        }

        return [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'lead_magnets_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }
}
