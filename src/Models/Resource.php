<?php

namespace Goldnead\LeadMagnets\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A gated resource: a file on a disk, or a link to somewhere else.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $handle
 * @property string $title
 * @property string|null $description
 * @property string $delivery_type
 * @property string|null $file_path
 * @property string|null $file_disk
 * @property string|null $link_url
 * @property bool $requires_confirmation
 * @property bool $published
 * @property int|null $link_ttl
 * @property int|null $max_downloads
 * @property int|null $grant_ttl_days
 * @property array<int, string>|null $tags
 * @property string|null $marketing_list
 */
class Resource extends Model
{
    use HasBrand;

    public const TYPE_FILE = 'file';

    public const TYPE_LINK = 'link';

    protected $table = 'lead_magnet_resources';

    protected $guarded = [];

    protected $casts = [
        'requires_confirmation' => 'boolean',
        'published' => 'boolean',
        'link_ttl' => 'integer',
        'max_downloads' => 'integer',
        'grant_ttl_days' => 'integer',
        'tags' => 'array',
    ];

    /** @return HasMany<Grant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(Grant::class, 'resource_id');
    }

    /**
     * How long a signed download link for this resource stays valid, in minutes.
     *
     * A resource may set its own; otherwise the config default applies. The
     * floor of one minute is not cosmetic: a zero would sign a link that is
     * already expired when the mail is written, which reads as "the download
     * is broken" and is very hard to diagnose from the outside.
     */
    public function linkTtlMinutes(): int
    {
        $ttl = $this->link_ttl ?: (int) config('lead-magnets.delivery.link_ttl', 10080);

        return max(1, $ttl);
    }

    public function maxDownloads(): ?int
    {
        $max = $this->max_downloads ?? config('lead-magnets.delivery.max_downloads');

        return $max === null ? null : max(1, (int) $max);
    }

    public function grantTtlDays(): ?int
    {
        $days = $this->grant_ttl_days ?? config('lead-magnets.delivery.grant_ttl_days');

        return $days === null ? null : max(1, (int) $days);
    }

    /** @return array<int, string> */
    public function tagList(): array
    {
        return array_values(array_filter(array_map(
            fn ($tag) => trim((string) $tag),
            $this->tags ?? []
        ), fn ($tag) => $tag !== ''));
    }

    public function isLink(): bool
    {
        return $this->delivery_type === self::TYPE_LINK;
    }

    public function disk(): string
    {
        return (string) ($this->file_disk
            ?: config('lead-magnets.delivery.disk')
            ?: config('filesystems.default', 'local'));
    }
}
