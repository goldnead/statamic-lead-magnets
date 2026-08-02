<?php

namespace Goldnead\LeadMagnets\Http\Controllers\Web;

use Goldnead\LeadMagnets\LeadMagnetsManager;
use Illuminate\Routing\Controller;

/**
 * The confirmation link.
 *
 * Renders the same page whether this click is the first or the fifth. The
 * difference — whether the file was sent — is decided by the conditional
 * UPDATE in GrantService::activate(), not here, so a mail client that
 * prefetches the URL and a reader who clicks afterwards together produce one
 * activation and one delivery mail.
 */
class ConfirmController extends Controller
{
    public function __invoke(string $token, LeadMagnetsManager $leadMagnets)
    {
        $grant = $leadMagnets->confirm($token);

        // An unknown token, a token from another brand and a token that was
        // already consumed are three different things behind the scenes and
        // one thing here: nothing to confirm.
        abort_unless($grant, 404);

        return response()->view('lead-magnets::confirmed', [
            'grant' => $grant,
            'resource' => $grant->resource,
            'lapsed' => $grant->isPending() && $grant->hasLapsed(),
        ]);
    }
}
