<?php

namespace Goldnead\LeadMagnets\Http\Controllers\Web;

use Goldnead\LeadMagnets\GrantState;
use Goldnead\LeadMagnets\LeadMagnetsManager;
use Goldnead\LeadMagnets\Models\Resource;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The form endpoint. Opened by strangers, so it says as little as possible.
 */
class RequestController extends Controller
{
    public function store(Request $request, LeadMagnetsManager $leadMagnets)
    {
        $honeypot = (string) config('lead-magnets.requests.honeypot', 'website');

        // A filled honeypot gets a believable success and nothing else.
        if ($honeypot !== '' && $request->filled($honeypot)) {
            return $this->respond($request, GrantState::PENDING);
        }

        $data = $request->validate([
            'email' => ['required', 'email'],
            'resource' => ['required', 'string', 'max:191'],
        ]);

        $resource = Resource::query()
            ->where('handle', $data['resource'])
            ->where('published', true)
            ->first();

        abort_if($resource === null, 404);

        $grant = $leadMagnets->request($resource, $data['email'], [
            'source' => 'form',
            'referer' => substr((string) $request->headers->get('referer'), 0, 255) ?: null,
        ]);

        return $this->respond($request, $grant->state);
    }

    protected function respond(Request $request, string $state)
    {
        // The state is safe to return: it says whether a confirmation is on
        // its way, which the visitor needs, and it says nothing about whether
        // this address had asked before.
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'data' => ['state' => $state]]);
        }

        if ($redirect = $request->input('_redirect')) {
            return redirect()->to($redirect);
        }

        return back()->with('lead-magnets.requested', $state);
    }
}
