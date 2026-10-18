<?php

namespace Goldnead\LeadMagnets\Events;

use Goldnead\LeadMagnets\Models\Grant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The placeholders a mail is about to be filled with.
 *
 * Fired for the confirmation mail (`kind` is `confirmation`) and the delivery
 * mail (`delivery`), after this addon has set its own. A listener adds or
 * replaces entries on `$variables`; they are then available to the template as
 * `{{ name }}`. Values are escaped like the others, so a link is meant to be an
 * `href` or plain text, not markup.
 *
 * A key the template does not use is harmless, and a key a template uses that
 * nobody sets holds the mail back with an error in the log, as before.
 */
class DeliveryVariables
{
    use Dispatchable;

    /**
     * @param  array<string, string>  $variables
     */
    public function __construct(
        public readonly Grant $grant,
        public readonly string $kind,
        public array $variables,
    ) {}
}
