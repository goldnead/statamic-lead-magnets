<?php

namespace Goldnead\LeadMagnets\Integrations;

use Goldnead\LeadMagnets\Models\Grant;

/**
 * Optional: goldnead/statamic-leadhub.
 *
 * Resolves the address to a contact when a grant activates and writes the
 * resource's tags onto it. Absent the addon this is silent and the grant is
 * still complete — the email address on the grant is the record that matters.
 */
class LeadhubBridge extends Bridge
{
    /** @var class-string */
    protected const FACADE = \Goldnead\Leadhub\Facades\LeadHub::class;

    public function available(): bool
    {
        return $this->enabled('leadhub') && class_exists(self::FACADE);
    }

    /**
     * Ensure a contact exists for this grant and tag it.
     *
     * Returns the contact id it settled on, or null. Idempotent: leadhub's
     * addTag is a set operation, and findByEmail before create means a second
     * activation of the same address does not make a second contact.
     */
    public function onActivated(Grant $grant): ?string
    {
        if (! $this->available()) {
            return null;
        }

        $facade = self::FACADE;

        return $this->attempt('leadhub contact sync', function () use ($facade, $grant) {
            $contact = null;

            if ($this->rootHas($facade, 'findByEmail')) {
                $contact = $facade::findByEmail($grant->email);
            }

            if (! $contact && $this->rootHas($facade, 'create')) {
                $contact = $facade::create([
                    'email' => $grant->email,
                    'source' => 'lead-magnets',
                ]);
            }

            $id = is_array($contact) ? ($contact['id'] ?? null) : ($contact?->id ?? null);

            if ($id === null) {
                return null;
            }

            if ($this->rootHas($facade, 'addTag')) {
                foreach ($grant->resource?->tagList() ?? [] as $tag) {
                    $facade::addTag($id, $tag);
                }
            }

            return (string) $id;
        });
    }
}
