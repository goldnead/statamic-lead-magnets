<?php

namespace Goldnead\LeadMagnets\Http\Controllers\Web;

use Goldnead\LeadMagnets\LeadMagnetsManager;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Services\GrantService;
use Illuminate\Routing\Controller;

/**
 * The confirmation link.
 *
 * Renders the same page whether this click is the first or the fifth. The
 * difference — whether the file was sent — is decided by the conditional
 * UPDATE in GrantService::activate(), not here, so a mail client that
 * prefetches the URL and a reader who clicks afterwards together produce one
 * activation and one delivery mail.
 *
 * ## A grant that also confirms a mailing list
 *
 * Opening the link is then not agreeing to it. Mail gateways and preview
 * features fetch every URL in a message, and for a file alone that is
 * harmless: the worst a scanner does is deliver the file to the inbox it came
 * from. A newsletter subscription is consent, and a scanner may not give it.
 * So GET shows the disclosure and a button and changes nothing; the POST from
 * that button confirms. marketing holds its own confirmation link to the same
 * rule (`subscriptions.confirm_requires_post`).
 */
class ConfirmController extends Controller
{
    public function show(string $token, LeadMagnetsManager $leadMagnets, GrantService $grants)
    {
        $grant = $grants->findByToken($token);

        abort_if($grant === null, 404);

        if ($grant->isPending() && $grant->couplesList() && ! $grant->confirmationLapsed()) {
            return response()->view('lead-magnets::confirm', [
                'grant' => $grant,
                'resource' => $grant->resource,
                'consent' => $grant->listConsent(),
                'action' => route('lead-magnets.confirm.store', ['token' => $token]),
            ]);
        }

        return $this->confirmed($leadMagnets->confirm($token));
    }

    public function store(string $token, LeadMagnetsManager $leadMagnets)
    {
        return $this->confirmed($leadMagnets->confirm($token));
    }

    protected function confirmed(?Grant $grant)
    {
        // An unknown token, a token from another brand and a token that was
        // already consumed are three different things behind the scenes and
        // one thing here: nothing to confirm.
        abort_if($grant === null, 404);

        return response()->view('lead-magnets::confirmed', [
            'grant' => $grant,
            'resource' => $grant->resource,
            'lapsed' => $grant->isPending() && $grant->confirmationLapsed(),
        ]);
    }
}
