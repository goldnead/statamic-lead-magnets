<?php

namespace Goldnead\LeadMagnets\Contracts;

use Goldnead\BrandContext\Contracts\SenderIdentityResolver as BrandContextResolver;

/**
 * An empty sub-interface, on purpose.
 *
 * The contract lives in statamic-brand-context, where the addons agreed on it
 * in August 2026 rather than keeping a copy each. What this adds is a name a
 * host can rebind for *this* package alone.
 */
interface SenderIdentityResolver extends BrandContextResolver {}
