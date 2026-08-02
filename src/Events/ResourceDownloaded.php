<?php

namespace Goldnead\LeadMagnets\Events;

use Goldnead\LeadMagnets\Models\Download;
use Goldnead\LeadMagnets\Models\Grant;

/**
 * A signed link was redeemed. Fires once per redemption, not once per grant.
 */
class ResourceDownloaded extends GrantEvent
{
    public function __construct(Grant $grant, public readonly Download $download)
    {
        parent::__construct($grant);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return parent::payload() + [
            'download_id' => $this->download->id,
            'downloaded_at' => $this->download->downloaded_at?->toIso8601String(),
        ];
    }
}
