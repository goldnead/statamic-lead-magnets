<?php

namespace Goldnead\LeadMagnets\Facades;

use Goldnead\LeadMagnets\LeadMagnetsManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Goldnead\LeadMagnets\Models\Resource|null resource(string $handle)
 * @method static \Goldnead\LeadMagnets\Models\Grant request(\Goldnead\LeadMagnets\Models\Resource $resource, string $email, array $meta = [])
 * @method static \Goldnead\LeadMagnets\Models\Grant|null confirm(string $token)
 * @method static \Goldnead\LeadMagnets\Models\Grant|null findGrant(\Goldnead\LeadMagnets\Models\Resource $resource, string $email)
 * @method static \Goldnead\Entitlements\Models\Entitlement|null entitlementFor(\Goldnead\LeadMagnets\Models\Grant $grant)
 * @method static string downloadUrl(\Goldnead\LeadMagnets\Models\Grant $grant)
 * @method static \Goldnead\LeadMagnets\Models\Grant revoke(\Goldnead\LeadMagnets\Models\Grant $grant, string $reason)
 * @method static \Goldnead\LeadMagnets\Models\Grant reinstate(\Goldnead\LeadMagnets\Models\Grant $grant)
 *
 * @see LeadMagnetsManager
 */
class LeadMagnets extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LeadMagnetsManager::class;
    }
}
