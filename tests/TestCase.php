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

    protected function getPackageProviders($app): array
    {
        return [
            \Goldnead\BrandContext\ServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->testingConnection());

        $app['config']->set('statamic.users.repository', 'file');
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.from', ['address' => 'noreply@example.com', 'name' => 'Test']);

        $app['config']->set('filesystems.disks.lead-magnets', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/lead-magnets'),
            'throw' => false,
        ]);
        $app['config']->set('lead-magnets.delivery.disk', 'lead-magnets');

        // The throttle would otherwise turn the eleventh request in a test
        // class into a 429 that has nothing to do with what is being tested.
        $app['config']->set('lead-magnets.requests.throttle', '10000,1');
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
