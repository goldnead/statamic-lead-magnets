<?php

use Goldnead\LeadMagnets\Facades\LeadMagnets;
use Goldnead\LeadMagnets\Integrations\SiblingBridges;
use Goldnead\LeadMagnets\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;

/*
 * What the service provider promises. Every item here has been a silent
 * failure somewhere in this family, and silence is the point: none of them
 * throws when it goes wrong, they simply stop happening.
 */

it('registers the addon translations under their own namespace', function () {
    expect(__('lead-magnets::nav.lead_magnets'))->toBe('Lead Magnets')
        ->and(__('lead-magnets::grants.state'))->toBe('State');
});

it('resolves the JSON strings the Vue layer asks for', function () {
    // The CP components call `__('Create resource')` with the English sentence
    // as the key, which resolves through the JSON loader rather than the
    // namespace above. Registering only the namespace leaves the nav German
    // and every screen behind it English, which reads worse than shipping no
    // translation at all.
    app('translator')->setLocale('de');

    expect(__('Create resource'))->toBe('Ressource anlegen');

    app('translator')->setLocale('en');
});

it('ships a German translation for every English one, and the reverse', function () {
    $en = json_decode(file_get_contents(__DIR__.'/../../resources/lang/en.json'), true);
    $de = json_decode(file_get_contents(__DIR__.'/../../resources/lang/de.json'), true);

    expect(array_diff_key($en, $de))->toBe([])
        ->and(array_diff_key($de, $en))->toBe([]);

    foreach (glob(__DIR__.'/../../resources/lang/en/*.php') as $file) {
        $german = dirname($file, 2).'/de/'.basename($file);

        expect(file_exists($german))->toBeTrue(basename($file).' has no German counterpart');
        expect(array_diff_key(require $file, require $german))->toBe([], 'keys missing from de/'.basename($file));
        expect(array_diff_key(require $german, require $file))->toBe([], 'extra keys in de/'.basename($file));
    }
});

it('reads its Vite configuration from the provider property, where Statamic 6 looks', function () {
    $property = (new ReflectionClass(ServiceProvider::class))->getProperty('vite');
    $property->setAccessible(true);

    $vite = $property->getValue(app()->getProvider(ServiceProvider::class));

    // Statamic 6 reads this and nothing else. `extra.statamic.vite` in
    // composer.json is a v5 key that v6 ignores in silence, leaving every CP
    // screen with no JavaScript and no styles.
    expect($vite['publicDirectory'])->toBe('resources/dist')
        ->and($vite['input'])->toBe(['resources/js/cp.js', 'resources/css/cp.css']);

    // And it has to byte-match what the Vite config builds.
    $config = file_get_contents(__DIR__.'/../../vite.config.js');

    expect($config)->toContain("publicDirectory: 'resources/dist'")
        ->toContain("'resources/js/cp.js'")
        ->toContain("'resources/css/cp.css'");

    expect(json_decode(file_get_contents(__DIR__.'/../../composer.json'), true)['extra']['statamic'])
        ->not->toHaveKey('vite');
});

it('registers the public and CP routes', function () {
    $names = collect(Route::getRoutes())->map(fn ($route) => $route->getName())->filter()->all();

    foreach ([
        'lead-magnets.request',
        'lead-magnets.confirm',
        'lead-magnets.download',
        'statamic.cp.lead-magnets.resources.index',
        'statamic.cp.lead-magnets.grants.revoke',
    ] as $name) {
        expect($names)->toContain($name);
    }
});

it('merges its config so a site that never published one still works', function () {
    // A published-but-unmerged config returns null on every install that did
    // nothing wrong, which is the worst possible group to break.
    expect(config('lead-magnets.routes.prefix'))->toBe('!/lead-magnets')
        ->and(config('lead-magnets.delivery.link_ttl'))->toBeInt();
});

it('registers the sweep exactly once, however often the app announces it booted', function () {
    // Statamic fires `app->booted()` callbacks twice. The bridge registration
    // leans on that and is idempotent for it; a schedule entry is not.
    // Marketing measured exactly this and found one call producing two entries
    // in `schedule:list` — harmless there only by accident.
    app()->booted(fn () => null);
    app()->booted(fn () => null);

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'lead-magnets:sweep'));

    expect($events)->toHaveCount(1);
});

it('exposes its public API through the facade', function () {
    $resource = makeResource();

    expect(LeadMagnets::resource('warm_up')?->id)->toBe($resource->id)
        ->and(LeadMagnets::resource('nothing-here'))->toBeNull();

    $grant = LeadMagnets::request($resource, 'reader@example.com');

    expect(LeadMagnets::findGrant($resource, 'READER@example.com')?->id)->toBe($grant->id);
});

it('leaves the sibling registrar unbooted and harmless with nothing installed', function () {
    expect(app(SiblingBridges::class)->booted())->toBeFalse();

    // And the flow still completes — proved end to end in
    // tests/Feature/NoSiblingsInstalledTest.php.
});

it('ships no debug leftovers', function () {
    $offenders = [];

    foreach (['src', 'resources/js', 'routes', 'config'] as $directory) {
        $path = __DIR__.'/../../'.$directory;

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

        foreach ($files as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'js', 'vue'], true)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            // Word-boundary matching, not str_contains: `is_array(` contains
            // `ray(`, and a check that flags it is a check people learn to
            // ignore.
            foreach (['dd', 'dump', 'ray', 'console\\.log'] as $needle) {
                if (preg_match('/(?<![\\w$>\\-])'.$needle.'\\s*\\(/', $contents)) {
                    $offenders[] = $file->getPathname().' → '.$needle;
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});
