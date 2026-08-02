<?php

namespace Goldnead\LeadMagnets\Events;

use Goldnead\LeadMagnets\Models\Grant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The base every domain event in this addon extends.
 *
 * One shape, because the consumers are integrations: a webhook trigger, an
 * activity producer, an automation. They map an event to a payload, and a
 * payload they can build the same way for all four is the difference between
 * one mapper and four.
 */
abstract class GrantEvent
{
    use Dispatchable;

    public function __construct(public readonly Grant $grant) {}

    /**
     * The event as an integration would want it.
     *
     * Deliberately free of the token and of anything signed: this payload
     * travels to webhook endpoints and activity ledgers, and a confirmation
     * secret in an outbound webhook is a confirmation anyone can replay.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $resource = $this->grant->resource;

        return [
            'grant_id' => $this->grant->id,
            'email' => $this->grant->email,
            'contact_id' => $this->grant->contact_id,
            'state' => $this->grant->state,
            'download_count' => $this->grant->download_count,
            'brand_id' => $this->grant->brand_id,
            'resource' => [
                'id' => $resource?->id,
                'handle' => $resource?->handle,
                'title' => $resource?->title,
                'delivery_type' => $resource?->delivery_type,
            ],
        ];
    }
}
