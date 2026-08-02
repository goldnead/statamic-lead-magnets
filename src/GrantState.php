<?php

namespace Goldnead\LeadMagnets;

/**
 * The grant lifecycle, owned by this addon.
 *
 * The platform's target architecture puts entitlements in a package of their
 * own (`goldnead/statamic-entitlements`) and has every consumer read grants
 * from there. That package is deferred: it is waiting for a second consumer
 * before its abstraction is worth designing, and lead-magnets is meant to be
 * that consumer. Taking the target architecture literally would mean not
 * building this addon at all.
 *
 * So the state lives here, deliberately, and the deviation is documented in
 * the README under "Grant state". When entitlements arrives there will be two
 * grant models and a migration between them; that cost is smaller than
 * designing the shared abstraction before the second real use case exists.
 *
 * Transitions, and only these:
 *
 *   (none)  -> pending    a request that needs confirmation
 *   (none)  -> active     a request for a resource that needs none
 *   pending -> active     the confirmation arrived (once, see GrantService)
 *   pending -> expired    the confirmation window closed
 *   active  -> expired    the grant's own lifetime ran out
 *   pending -> revoked    withdrawn in the Control Panel
 *   active  -> revoked    withdrawn in the Control Panel
 *
 * `revoked` is terminal. `expired` is terminal for this grant, but a fresh
 * request for the same resource reopens the same row as pending.
 */
final class GrantState
{
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const REVOKED = 'revoked';

    public const EXPIRED = 'expired';

    /** @var list<string> */
    public const ALL = [self::PENDING, self::ACTIVE, self::REVOKED, self::EXPIRED];

    /** States from which a grant may still be delivered. */
    public const DELIVERABLE = [self::ACTIVE];

    private function __construct() {}

    public static function isKnown(string $state): bool
    {
        return in_array($state, self::ALL, true);
    }
}
