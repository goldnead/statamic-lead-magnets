<?php

namespace Goldnead\LeadMagnets\Support;

use Goldnead\LeadMagnets\Models\Grant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Where the confirmation link sends the reader afterwards.
 *
 * A caller that asked for a resource on someone's behalf (a funnel, a course, a
 * checkout) can hand over a **return URL** in the request's meta. After the
 * reader confirmed, the confirmation link redirects there instead of showing
 * this addon's own page. The addon knows nothing about who asked: it only
 * knows that a URL was handed over and whether it may follow it.
 *
 * ## Why a signed URL, and nothing else
 *
 * A redirect target that arrives in a request is the textbook open redirect:
 * somebody mails a victim a link on the real domain that lands on a phishing
 * page. The target is not accepted from anywhere a visitor can write to. It
 * is only read from `meta['return_url']` of {@see GrantService::request()},
 * which is PHP code, never the public form (see `RequestController`), and it
 * is only kept when it is
 *
 * 1. **signed** with this application's key (`URL::signedRoute()` and
 *    `URL::temporarySignedRoute()` produce such a URL, nothing a visitor can
 *    forge or edit), and
 * 2. on **this site's host**, so even an application that signs a link to
 *    another host cannot turn the confirmation into a bounce off it.
 *
 * The check runs twice: when the URL is stored, and again when it is followed.
 * A grant row edited by hand, or a key that rotated since, then ends on the
 * ordinary confirmation page instead of on whatever is in the column.
 */
final class ReturnUrl
{
    /** The key in `meta` and on {@see Grant::$meta}. */
    public const META = 'return_url';

    private function __construct() {}

    /**
     * The URL if it may be followed, otherwise null.
     */
    public static function accept(mixed $url, ?Request $current = null): ?string
    {
        if (! is_string($url) || $url === '' || strlen($url) > 2048) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! isset($parts['host'])
            // Credentials in the URL are a way to make the host look like
            // something else in front of the visitor.
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        $current ??= request();

        if (strcasecmp($parts['host'], $current->getHost()) !== 0) {
            return null;
        }

        // A signed URL is only valid for the host and scheme it was signed for,
        // and `hasValidSignature()` reads those from the request it is given.
        if (! URL::hasValidSignature(Request::create($url))) {
            return null;
        }

        return $url;
    }

    /** The URL stored on a grant, checked again. */
    public static function for(Grant $grant, ?Request $current = null): ?string
    {
        return self::accept($grant->meta[self::META] ?? null, $current);
    }
}
