<?php

namespace Goldnead\LeadMagnets\Integrations;

use Goldnead\Leadhub\Facades\LeadHub;
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
    /**
     * The sibling class this bridge speaks to.
     *
     * A method rather than a hard-coded constant so a host application can
     * point the bridge at a fork or a decorator, and so the guard logic can be
     * exercised against a stand-in without aliasing anything into the sibling
     * namespace — a global mutation that cannot be undone and leaks into every
     * later test in the process.
     *
     * @return class-string
     */
    protected function facade(): string
    {
        return LeadHub::class;
    }

    public function available(): bool
    {
        return $this->enabled('leadhub') && class_exists($this->facade());
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

        $facade = $this->facade();

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

            // leadhub answers with an array; a decorated or forked
            // implementation may answer with an object. Both are read, and
            // neither is assumed.
            $id = match (true) {
                is_array($contact) => $contact['id'] ?? null,
                is_object($contact) => $contact->id ?? null,
                default => null,
            };

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
