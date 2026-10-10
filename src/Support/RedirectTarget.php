<?php

namespace Goldnead\LeadMagnets\Support;

use Illuminate\Http\Request;

/**
 * Where the request form may send the reader afterwards (`_redirect`).
 *
 * The field is written by whoever builds the request, and the endpoint is
 * public, so anything in it can be forged into a link on this site's domain
 * that lands on a phishing page. Only two shapes are followed:
 *
 * 1. a **path on this site**: exactly one leading `/`, no second `/` or
 *    backslash right behind it (`//host` and `/\host` are read by browsers as
 *    another host), and
 * 2. an **absolute http(s) URL** whose scheme, host and port are this
 *    request's own, with no credentials in it.
 *
 * Anything else yields null and the caller keeps its default destination.
 */
final class RedirectTarget
{
    private function __construct() {}

    public static function accept(mixed $target, ?Request $current = null): ?string
    {
        if (! is_string($target) || $target === '' || strlen($target) > 2048) {
            return null;
        }

        // Whitespace, control characters and backslashes are stripped or
        // reinterpreted by browsers ("/\t/evil.example" becomes "//evil.example").
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $target) === 1) {
            return null;
        }

        if ($target[0] === '/') {
            return str_starts_with($target, '//') ? null : $target;
        }

        $parts = parse_url($target);

        if ($parts === false
            || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        $current ??= request();

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($scheme, ['http', 'https'], true)
            || $scheme !== $current->getScheme()
            || strcasecmp($parts['host'], $current->getHost()) !== 0
            || $port !== $current->getPort()) {
            return null;
        }

        return $target;
    }
}
