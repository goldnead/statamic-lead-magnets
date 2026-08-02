<?php

namespace Goldnead\LeadMagnets\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\LeadMagnets\GrantState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One person's access to one resource.
 *
 * @property int $id
 * @property int $brand_id
 * @property int $resource_id
 * @property string $email
 * @property string|null $contact_id
 * @property string $state
 * @property string|null $token_hash
 * @property Carbon|null $requested_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $expires_at
 * @property int $download_count
 * @property array<string, mixed>|null $meta
 */
class Grant extends Model
{
    use HasBrand;

    protected $table = 'lead_magnet_grants';

    protected $guarded = [];

    protected $casts = [
        'requested_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'delivered_at' => 'datetime',
        'revoked_at' => 'datetime',
        'expires_at' => 'datetime',
        'download_count' => 'integer',
        'meta' => 'array',
    ];

    /**
     * The plaintext confirmation token, for exactly as long as this object
     * lives.
     *
     * Never persisted, never serialised — `$hidden` and the absence of a
     * column see to that. The service sets it when it mints a token so the
     * mail can carry it, and nothing else ever reads it back.
     */
    public ?string $plainToken = null;

    protected $hidden = ['token_hash'];

    /**
     * Fully qualified in the docblock on purpose: Pint's `phpdoc_types` fixer
     * rewrites a bare `Resource` to PHP's native `resource` type, and the
     * generic then reads as a mismatch between two identical-looking strings.
     *
     * @return BelongsTo<\Goldnead\LeadMagnets\Models\Resource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'resource_id');
    }

    /** @return HasMany<Download, $this> */
    public function downloads(): HasMany
    {
        return $this->hasMany(Download::class, 'grant_id');
    }

    /** @param  Builder<$this>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('state', GrantState::ACTIVE);
    }

    /** @param  Builder<$this>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('state', GrantState::PENDING);
    }

    public function isActive(): bool
    {
        return $this->state === GrantState::ACTIVE;
    }

    public function isPending(): bool
    {
        return $this->state === GrantState::PENDING;
    }

    /**
     * Whether the grant's own lifetime has run out.
     *
     * Read separately from the `expired` state on purpose: the state is
     * written by whoever notices first (a request, a download attempt, the
     * sweep command), and until someone does, a row can be past its date and
     * still say `active`. Everything that gates on access asks this, not the
     * column, so no access ever depends on a sweep having run.
     */
    public function hasLapsed(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function downloadsExhausted(): bool
    {
        $max = $this->resource?->maxDownloads();

        return $max !== null && $this->download_count >= $max;
    }

    /** The single question every delivery path asks. */
    public function isRedeemable(): bool
    {
        return $this->isActive()
            && ! $this->hasLapsed()
            && ! $this->downloadsExhausted();
    }
}
