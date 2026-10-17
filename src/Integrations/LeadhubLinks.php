<?php

namespace Goldnead\LeadMagnets\Integrations;

use Goldnead\Leadhub\Facades\LeadHub;

/**
 * Optional: goldnead/statamic-leadhub. "Is there a LeadHub contact behind this
 * address, and may this reader open it?" for the "Access" table.
 *
 * Same idea as statamic-marketing's `Support\ContactLinks` (campaign report),
 * but reached over LeadHub's public facade instead of its repositories, so
 * there is no hard dependency and nothing to import from the sibling.
 *
 * Each row ends in one of three visible states, never a silent guess:
 *
 *  - `linked`: exactly one contact for the address, and it is the one the
 *    grant was synced to (or the grant was never synced). Url set.
 *  - `ambiguous`: the address resolves to a different contact than the id
 *    stored on the grant (address changed, contacts merged). No url; the UI
 *    says so instead of jumping to the first candidate.
 *  - `none`: no contact. Plain text.
 *
 * Without LeadHub, or for a reader without `view leadhub contacts`, the map is
 * empty and the cell stays what it always was: text.
 */
class LeadhubLinks extends Bridge
{
    /** @return class-string */
    protected function facade(): string
    {
        return LeadHub::class;
    }

    public function available(): bool
    {
        return $this->enabled('leadhub')
            && class_exists($this->facade())
            && $this->rootHas($this->facade(), 'findByEmail');
    }

    protected function mayView(): bool
    {
        return (bool) auth()->user()?->can('view leadhub contacts');
    }

    protected function contactUrl(string $uuid): string
    {
        return cp_route('leadhub.contacts.show', $uuid);
    }

    /**
     * @param  iterable<int, array{email: ?string, contact_id: mixed}>  $grants
     * @return array<string, array{state: string, url: ?string}> keyed by the address as given
     */
    public function forGrants(iterable $grants): array
    {
        if (! $this->available() || ! $this->mayView()) {
            return [];
        }

        $map = [];

        foreach ($grants as $grant) {
            $email = $grant['email'] ?? null;

            if (! is_string($email) || trim($email) === '' || isset($map[$email])) {
                continue;
            }

            $resolved = $this->attempt('leadhub contact link', fn () => $this->resolve($email, $grant['contact_id'] ?? null));

            // A failing lookup is not "no contact": leave the row out, so it
            // renders as plain text without claiming anything. LeadHub is
            // down for the rest of the page too, so stop after the first
            // failure (one log line, not fifty).
            if ($resolved === null) {
                break;
            }

            $map[$email] = $resolved;
        }

        return $map;
    }

    /** @return array{state: string, url: ?string} */
    protected function resolve(string $email, mixed $storedId): array
    {
        $facade = $this->facade();
        $contact = $facade::findByEmail(mb_strtolower(trim($email)));

        $contact = is_object($contact) ? (array) $contact : $contact;

        if (! is_array($contact) || empty($contact['uuid'])) {
            return ['state' => 'none', 'url' => null];
        }

        if ($storedId !== null && $storedId !== '' && isset($contact['id']) && (string) $contact['id'] !== (string) $storedId) {
            return ['state' => 'ambiguous', 'url' => null];
        }

        return ['state' => 'linked', 'url' => $this->contactUrl((string) $contact['uuid'])];
    }
}
