<?php

namespace Goldnead\LeadMagnets\Services;

use Goldnead\LeadMagnets\Models\Grant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * The signed, time-boxed download URL.
 *
 * Laravel's own signed URLs, not a scheme of this addon's making. The
 * signature covers the whole URL including the expiry, so there is no way to
 * move the deadline, point the link at another grant or add a parameter
 * without invalidating it — and `signed` middleware answers 403 before the
 * controller ever runs.
 *
 * Nothing about the file is in the URL. The grant id is, the resource is read
 * from the grant, and the path on disk never leaves the server: a signed URL
 * that named the file would let a valid link be edited into a different file
 * only if the signature broke, which it would — but it would also print the
 * storage layout into every mailbox for no benefit.
 */
class DownloadLink
{
    public function for(Grant $grant, ?Carbon $expiresAt = null): string
    {
        $resource = $grant->resource;

        $expiresAt ??= Carbon::now()->addMinutes(
            $resource?->linkTtlMinutes() ?? (int) config('lead-magnets.delivery.link_ttl', 10080)
        );

        // The grant's own lifetime is a ceiling on the link's. Without this a
        // 7-day link handed out on the last day of a grant would outlive the
        // access it grants — the controller would refuse it anyway, but a
        // link that is valid and refused is the worst of both.
        //
        // Read from the entitlement, and from whichever of its two dates is
        // actually holding the door open: a grant inside a grace period has an
        // `expires_at` in the past and access all the same.
        $endsAt = $grant->accessEndsAt();

        if ($endsAt !== null && $endsAt->lt($expiresAt)) {
            $expiresAt = Carbon::instance($endsAt);
        }

        return URL::temporarySignedRoute(
            'lead-magnets.download',
            $expiresAt,
            ['grant' => $grant->getKey()],
        );
    }
}
