<?php

namespace Goldnead\LeadMagnets\Models;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\StateResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One person's claim on one resource: the delivery record.
 *
 * ## What this row is, and what it is no longer
 *
 * Up to 1.x this row carried its own `state` column and its own four-state
 * lifecycle, because `goldnead/statamic-entitlements` did not exist yet. It
 * does now, and access state lives there — one state machine for the whole
 * platform instead of one per addon.
 *
 * What stayed here is everything entitlements has no business knowing: the
 * address that asked, the hashed confirmation secret, when the delivery mail
 * went out, how often the file was fetched and by which client. Access is a
 * platform question; delivery is this addon's job.
 *
 * The link is `entitlement_id`, one to one. Reading state means asking the
 * entitlement, never this row — there is no second copy of the rules here, and
 * that is the whole point of the move.
 *
 * @property int $id
 * @property int $brand_id
 * @property int $resource_id
 * @property string $email
 * @property string|null $contact_id
 * @property int|null $entitlement_id
 * @property int $attempt
 * @property string|null $token_hash
 * @property Carbon|null $requested_at
 * @property Carbon|null $confirm_expires_at
 * @property Carbon|null $delivered_at
 * @property int $download_count
 * @property array<string, mixed>|null $meta
 * @property-read Entitlement|null $entitlement
 */
class Grant extends Model
{
    use HasBrand;

    protected $table = 'lead_magnet_grants';

    protected $guarded = [];

    protected $casts = [
        'requested_at' => 'datetime',
        'confirm_expires_at' => 'datetime',
        'delivered_at' => 'datetime',
        'download_count' => 'integer',
        'attempt' => 'integer',
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

    /**
     * Whether the call that produced this object was the one that activated it.
     *
     * Transient, like {@see self::$plainToken}, and for the same kind of reason:
     * delivery for a first activation happens on the entitlements event, so a
     * caller that also delivers would send twice. Only the caller that
     * activated can know that, and only for as long as it holds this object.
     */
    public bool $justActivated = false;

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

    /** @return BelongsTo<Entitlement, $this> */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(Entitlement::class, 'entitlement_id');
    }

    /** @return HasMany<Download, $this> */
    public function downloads(): HasMany
    {
        return $this->hasMany(Download::class, 'grant_id');
    }

    /**
     * The one question about access, answered in the one place that decides it.
     *
     * A grant with no entitlement yet — a 1.x row that
     * `lead-magnets:migrate-grants` has not reached — resolves to Pending, which
     * grants nothing. Failing closed here is deliberate: the alternative is an
     * unmigrated row that serves files.
     */
    public function state(): EntitlementState
    {
        return $this->entitlement?->state() ?? EntitlementState::Pending;
    }

    public function stateValue(): string
    {
        return $this->state()->value;
    }

    public function isActive(): bool
    {
        return $this->state() === EntitlementState::Active;
    }

    public function isPending(): bool
    {
        return $this->state() === EntitlementState::Pending;
    }

    /**
     * Whether the access window has closed.
     *
     * Derived, never stored. In 1.x this was a column that whoever noticed
     * first wrote — a request, a download attempt, the sweep — so a row could
     * be past its date and still say `active` until something swept it. The
     * entitlements resolver reads the clock, so there is nothing left to sweep
     * and nothing left to be stale.
     */
    public function hasLapsed(): bool
    {
        return $this->state() === EntitlementState::Expired;
    }

    /** Whether the confirmation link's own window has closed. */
    public function confirmationLapsed(): bool
    {
        return $this->confirm_expires_at !== null && $this->confirm_expires_at->isPast();
    }

    public function downloadsExhausted(): bool
    {
        $max = $this->resource?->maxDownloads();

        return $max !== null && $this->download_count >= $max;
    }

    /**
     * The single question every delivery path asks.
     *
     * `grantsAccess()` rather than a comparison against Active: a grant an
     * operator put into a grace period in the entitlements Control Panel still
     * grants access, and a download gate that only knew about Active would
     * refuse it. That list lives on the enum, once, for exactly this reason.
     */
    public function isRedeemable(): bool
    {
        return $this->state()->grantsAccess() && ! $this->downloadsExhausted();
    }

    /** When the address was proven. Entitlements records it as the grant's start. */
    public function confirmedAt(): ?CarbonImmutable
    {
        $entitlement = $this->entitlement;

        if ($entitlement === null || $entitlement->state() === EntitlementState::Pending) {
            return null;
        }

        return $entitlement->starts_at;
    }

    public function revokedAt(): ?CarbonImmutable
    {
        return $this->entitlement?->revoked_at;
    }

    /**
     * The instant access ends, or null when it does not.
     *
     * `grace_until` when a grace period is running, because that is what
     * actually holds the door open then; `expires_at` otherwise. Used as the
     * ceiling on a signed download link, so a link never outlives the access
     * it was issued for.
     */
    public function accessEndsAt(): ?CarbonImmutable
    {
        $entitlement = $this->entitlement;

        if ($entitlement === null) {
            return null;
        }

        return $entitlement->state() === EntitlementState::GracePeriod
            ? $entitlement->grace_until
            : $entitlement->expires_at;
    }

    /**
     * Constrain a grant query to the rows whose entitlement is in `$state`.
     *
     * Delegates the state expression to entitlements' own SQL projection rather
     * than restating it. A listing that filtered on its own copy of the rules
     * would be the second implementation this whole move exists to delete.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInState(Builder $query, EntitlementState $state): void
    {
        $query->whereIn(
            'entitlement_id',
            StateResolver::constrain(Entitlement::query()->select('id'), $state)
        );
    }
}
