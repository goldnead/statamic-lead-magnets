<?php

namespace Goldnead\LeadMagnets\Support;

/**
 * One spelling per address, so the unique index means what it says.
 *
 * Case-folds and trims. It does not strip dots or `+tags` from the local part:
 * those are provider policy, not the standard, and normalising them would
 * merge two addresses a strict provider treats as different — which in a
 * consent record is a mistake in the direction that matters.
 */
final class EmailNormalizer
{
    private function __construct() {}

    public static function normalize(?string $email): string
    {
        $email = trim((string) $email);

        if ($email === '' || ! str_contains($email, '@')) {
            return mb_strtolower($email);
        }

        [$local, $domain] = [
            mb_substr($email, 0, mb_strrpos($email, '@')),
            mb_substr($email, mb_strrpos($email, '@') + 1),
        ];

        // The local part is case-sensitive per RFC 5321 and case-insensitive in
        // practice at every provider anyone reading this will ever mail. The
        // practical reading wins, or the same person gets two grants.
        return mb_strtolower($local).'@'.mb_strtolower($domain);
    }
}
