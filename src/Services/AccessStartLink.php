<?php

namespace Goldnead\LeadMagnets\Services;

use Goldnead\IdentityContracts\AutoLogin\AutoLogin;
use Goldnead\LeadMagnets\Contracts\AccessSubjectResolver;
use Goldnead\LeadMagnets\Models\Grant;

/**
 * The "start now" link on the confirmed page of an access resource.
 *
 * The reader has just proven the address with the confirmation link, so the
 * page may log them in, the way the delivery mail does (see
 * DeliveryService::withAutoLogin()). Three limits keep that from becoming a
 * standing login:
 *
 * - The link is minted for the address of the grant behind the token, never
 *   for another one, and only while the grant's access stands.
 * - The confirmation link itself never expires, and every open of it would
 *   mint a login of its own. So the button is only offered while the
 *   confirmation is as young as a login link would be
 *   (`identity-contracts.autologin.ttl_days`, 14 by default). An older open
 *   still shows the page, just without a login.
 * - The link is signed and expires on its own (identity-contracts).
 *
 * Returns null, and the page shows no button, whenever one of these does not
 * hold, the identity package is missing or off, or the host offers no URL.
 */
class AccessStartLink
{
    public function for(Grant $grant): ?string
    {
        if (! config('lead-magnets.confirmed_start_link', true) || ! config('lead-magnets.auto_login', true)) {
            return null;
        }

        $resource = $grant->resource;

        if ($resource === null || ! $resource->isAccess() || ! $grant->isActive() || ! $this->isYoung($grant)) {
            return null;
        }

        $class = '\\Goldnead\\IdentityContracts\\AutoLogin\\AutoLogin';

        if (! class_exists($class) || ! app()->bound(AccessSubjectResolver::class)) {
            return null;
        }

        // A convenience for the reader must never cost the page.
        try {
            /** @var AutoLogin $links */
            $links = app($class);

            if (! $links->enabled()) {
                return null;
            }

            $resolver = app(AccessSubjectResolver::class);
            $subject = $resolver->subjectFor($grant->email, $grant);

            // Optional method of the host's resolver, not part of the interface, so a
            // host can add it before it has this addon version:
            // `startUrl(mixed $subject, Resource $resource, Grant $grant): ?string`,
            // the plain URL of the first thing to open (never a login link).
            $url = method_exists($resolver, 'startUrl')
                ? $resolver->startUrl($subject, $resource, $grant)
                : (trim((string) $resource->access_path) !== '' ? url($resource->access_path) : null);

            return $url === null ? null : $links->linkFor($grant->email, $url);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function isYoung(Grant $grant): bool
    {
        $confirmed = $grant->confirmedAt();
        $days = max(1, (int) config('identity-contracts.autologin.ttl_days', 14));

        return $confirmed !== null && $confirmed->greaterThanOrEqualTo(now()->subDays($days));
    }
}
