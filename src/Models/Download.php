<?php

namespace Goldnead\LeadMagnets\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One redemption of one grant. The audit row the spec asks for: who, when,
 * how often, from which request.
 *
 * @property int $id
 * @property int $brand_id
 * @property int $grant_id
 * @property \Illuminate\Support\Carbon|null $downloaded_at
 * @property string|null $ip_hash
 * @property string|null $user_agent
 */
class Download extends Model
{
    use HasBrand;

    protected $table = 'lead_magnet_downloads';

    protected $guarded = [];

    protected $casts = [
        'downloaded_at' => 'datetime',
    ];

    /** @return BelongsTo<Grant, $this> */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class, 'grant_id');
    }
}
