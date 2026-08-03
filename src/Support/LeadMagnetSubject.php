<?php

namespace Goldnead\LeadMagnets\Support;

use Goldnead\Entitlements\Support\SubjectReference;

/**
 * Who a lead-magnet grant belongs to, expressed the way entitlements wants it.
 *
 * Entitlements addresses a subject as a `(type, id)` pair rather than a foreign
 * key, precisely so that somebody who has no user account can hold a grant. A
 * lead magnet is claimed by an email address and often by nobody else: there is
 * no login, frequently no CRM contact, and the address is the only identity in
 * play.
 *
 * ## Why the id is a hash and not the address
 *
 * Two reasons, and the second is the load-bearing one.
 *
 * `entitlements.subject_id` is `VARCHAR(64)` and carries a 64-character prefix
 * in the unique index. An email address may be 254 characters long, so storing
 * it raw would silently truncate — and two addresses sharing their first 64
 * characters would then collide on a unique index that decides access.
 *
 * And the address is already stored, once, on `lead_magnet_grants`. Writing it
 * a second time into a package that has no reason to know it spreads personal
 * data across two tables and two deletion paths. A SHA-256 of the normalised
 * address is stable, fixed-width, and enough for entitlements to answer "does
 * this subject have access" without ever learning who the subject is.
 *
 * The consequence, stated plainly: the entitlements Control Panel shows an
 * opaque key for these grants. The readable list is this addon's own screen,
 * which has the address. That is the right side of the trade.
 *
 * ## Normalisation
 *
 * Through {@see EmailNormalizer}, the same function the grant row uses. If the
 * two ever diverged, a grant and its entitlement would belong to two different
 * subjects and access would depend on which one was asked.
 */
final class LeadMagnetSubject
{
    private function __construct() {}

    public static function for(string $email): SubjectReference
    {
        return new SubjectReference(self::type(), self::id($email));
    }

    /**
     * The morph type these subjects are stored under.
     *
     * Configurable because a host application that already models contacts may
     * want its own alias here, so that entitlements written by this addon and
     * by the host land on the same subject. Changing it after grants exist
     * orphans them; the setting is documented as install-time only.
     */
    public static function type(): string
    {
        return (string) config('lead-magnets.entitlements.subject_type', 'lead-magnet-contact');
    }

    public static function id(string $email): string
    {
        return hash('sha256', EmailNormalizer::normalize($email));
    }

    /** The grant source recorded on every entitlement this addon writes. */
    public static function source(): string
    {
        return (string) config('lead-magnets.entitlements.source', 'lead_magnet');
    }
}
