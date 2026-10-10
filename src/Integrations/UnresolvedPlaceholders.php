<?php

namespace Goldnead\LeadMagnets\Integrations;

use RuntimeException;

/**
 * An email-templates template that still contains `{{ … }}` after every
 * variable this addon knows was put in.
 *
 * Delivery templates support plain variables only. An Antlers `{{ if }}` is
 * not evaluated here, and a relay such as Brevo may silently drop a mail that
 * carries one. The bridge throws this instead of returning the text, so the
 * caller does not send it and does not call the grant delivered.
 */
class UnresolvedPlaceholders extends RuntimeException
{
    public function __construct(
        public readonly string $slug,
        public readonly string $snippet,
    ) {
        parent::__construct('the template ['.$slug.'] still contains ['.$snippet.'] after the variables were filled in.');
    }
}
