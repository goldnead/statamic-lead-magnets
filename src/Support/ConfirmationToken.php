<?php

namespace Goldnead\LeadMagnets\Support;

/**
 * The confirmation secret.
 *
 * Minted with `random_bytes`, stored only as a SHA-256 hash, compared in
 * constant time. Three properties, each of which has been someone's incident:
 *
 * - Not `Str::uuid()` or `Str::random()`'s default: a confirmation link is a
 *   bearer credential and wants a CSPRNG, not a convenience helper.
 * - Not stored in the clear: a leaked backup must not be a stack of working
 *   confirmations for addresses that never confirmed.
 * - Not compared with `===` after a lookup by hash — the lookup is by hash and
 *   is therefore already constant-work; `hash_equals` guards the one place a
 *   caller might compare a candidate against a known hash directly.
 */
final class ConfirmationToken
{
    private function __construct() {}

    public static function mint(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function matches(string $token, ?string $hash): bool
    {
        return $hash !== null && hash_equals($hash, self::hash($token));
    }
}
