<?php

namespace Goldnead\LeadMagnets\Http\Middleware;

use Closure;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Support\ConfirmationToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Derives the brand from a confirmation token on a session-less route.
 *
 * brand-context's own `SetBrandFromRouteValue` would be the right tool, and it
 * cannot be used here for one reason: it compares the route value to the
 * column as-is, and this addon stores the token hashed. Hashing first and then
 * handing the digest to the same lookup keeps the one property that makes that
 * middleware safe — the column carries a unique index across all brands, so a
 * value addresses exactly one record or none.
 *
 * Nothing is aborted here. An unknown token leaves no brand current, the
 * fail-closed scope hides everything, and the controller answers exactly as it
 * would for a token belonging to another brand: 404. That equivalence is the
 * point — otherwise the response time alone would tell a stranger whether a
 * token exists somewhere else in the installation.
 */
class SetBrandFromConfirmationToken
{
    public function handle(Request $request, Closure $next, string $parameter = 'token'): Response
    {
        $manager = app('brand-context');

        if (! $manager->multiBrandEnabled()) {
            return $next($request);
        }

        $value = $request->route($parameter) ?? $request->input($parameter);

        $brand = is_string($value) && $value !== ''
            ? $manager->brandForUnique(Grant::class, 'token_hash', ConfirmationToken::hash($value))
            : null;

        $brand ? $manager->setCurrent($brand) : $manager->forget();

        return $next($request);
    }
}
