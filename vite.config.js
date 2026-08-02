import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import statamic from '@statamic/cms/vite-plugin';

/**
 * One config, two jobs.
 *
 * Building the CP bundle uses the Statamic plugin, which rewrites `vue` to
 * `window.Vue` and leaves `@statamic/cms/*` to the host application — correct
 * for a bundle that runs inside the Control Panel, fatal in a test process,
 * where there is no `window.Vue` and the imports have to resolve for real.
 *
 * Under Vitest the SFCs are therefore compiled with the plain Vue plugin.
 *
 * The three values handed to `laravel()` must byte-match the provider's
 * `$vite` property. Statamic 6 reads the addon's Vite configuration from that
 * property and from nowhere else — not from `extra.statamic.vite` in
 * composer.json, which is a v5 key that v6 silently ignores.
 */
const isTest = !! process.env.VITEST;

export default defineConfig({
    plugins: isTest
        ? [vue()]
        : [
            laravel({
                input: [
                    'resources/js/cp.js',
                    'resources/css/cp.css',
                ],
                // Statamic's AddonServiceProvider publishes the compiled assets
                // from <publicDirectory>/build — the same directory configured
                // on the provider's $vite property. laravel-vite-plugin emits
                // the manifest flat at resources/dist/build/manifest.json,
                // where the Statamic Vite tag looks for it.
                publicDirectory: 'resources/dist',
                refresh: true,
            }),
            // Externalises `vue` to the CP's runtime build and registers the
            // Vue plugin, so this addon's @statamic/cms/* imports resolve
            // against the host Control Panel instead of being re-bundled.
            // Omitting it ships a second Vue instance, and provide/inject then
            // returns null with no error anywhere.
            statamic(),
            tailwindcss(),
        ],

    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        setupFiles: ['tests/js/setup.js'],
    },
});
