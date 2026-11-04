<?php

namespace Goldnead\LeadMagnets\Contracts;

use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;

/**
 * Optional companion of {@see AccessSubjectResolver}: where the "start now"
 * button on the confirmed page leads.
 *
 * Without it the button leads to `$resource->access_path`. A host that knows
 * more (the first lesson of a course, say) implements this on the same class
 * it binds as the resolver.
 *
 * The addon signs the reader in on the way (a login link of
 * `goldnead/statamic-identity-contracts`), so the answer is a plain URL to a
 * page behind the login, never a login link itself.
 */
interface ProvidesAccessStart
{
    /**
     * The plain URL of the first thing to open, or null for "no button".
     * Runs after the confirmation, for an access that stands.
     */
    public function startUrl(mixed $subject, Resource $resource, Grant $grant): ?string;
}
