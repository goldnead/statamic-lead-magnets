/**
 * Lead Magnets — Statamic 6 Control Panel entry point.
 *
 * Each page registered here corresponds to an `Inertia::render('lead-magnets::…')`
 * call on the PHP side. The string identifier MUST match exactly — a mismatch
 * renders a blank screen with nothing in the log.
 */

import ResourcesIndex from './pages/Resources/Index.vue';
import ResourcesEdit from './pages/Resources/Edit.vue';
import ResourcesShow from './pages/Resources/Show.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('lead-magnets::Resources/Index', ResourcesIndex);
    Statamic.$inertia.register('lead-magnets::Resources/Edit', ResourcesEdit);
    Statamic.$inertia.register('lead-magnets::Resources/Show', ResourcesShow);
});
