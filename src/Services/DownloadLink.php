<?php

namespace Goldnead\LeadMagnets\Services;

use Goldnead\LeadMagnets\Models\Grant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * The signed, time-boxed download URL.
 *
 * Laravel's own signed URLs, not a scheme of this addon's making. The
 * signature covers the whole URL including the expiry, so there is no way to
 * move the deadline, point the link at another grant or add a parameter
 * without invalidating it — and `signed` middleware answers 403 before the
 * controller ever runs.
 *
 * The path on disk is never in the URL. The grant id is, and the resource is
 * read from the grant. For a resource with a list of files the URL also names
 * the file by its key: a short random token stored with the list entry, with no
 * relation to the path or the file name. The key is covered by the signature
 * like everything else, so editing it to name another file breaks the hash; and
 * a URL that carried the path would print the storage layout into every
 * mailbox for no benefit.
 */
class DownloadLink
{
    public function for(Grant $grant, ?Carbon $expiresAt = null): string
    {
        return URL::temporarySignedRoute(
            'lead-magnets.download',
            $this->expiry($grant, $expiresAt),
            ['grant' => $grant->getKey()],
        );
    }

    /**
     * The signed link to one file of a multi-file resource.
     *
     * Same signature scheme, same lifetime rules as {@see self::for()}: the
     * key is part of the signed URL, so editing it to name another file breaks
     * the hash like editing the grant id does.
     */
    public function forFile(Grant $grant, string $fileKey, ?Carbon $expiresAt = null): string
    {
        return URL::temporarySignedRoute(
            'lead-magnets.download.file',
            $this->expiry($grant, $expiresAt),
            ['grant' => $grant->getKey(), 'file' => $fileKey],
        );
    }

    /**
     * Every file of the grant's resource with its own signed link, grouped.
     *
     * A group is the name an editor typed in the Control Panel, and groups come
     * in order of their first appearance in the list — not alphabetically, and
     * not in the order of the rows' last occurrence — so the editor's ordering
     * is what the reader sees. Files without a group form one unnamed group
     * (`name` null) at the position of the first such file. A file without a
     * label is shown under its file name.
     *
     * `$ceiling` caps every link: the download page passes its own expiry, so a
     * link on the page cannot outlive the page link that produced it.
     *
     * @return list<array{name: string|null, files: list<array{key: string, label: string, url: string}>}>
     */
    public function groupedFor(Grant $grant, ?Carbon $ceiling = null): array
    {
        $groups = [];

        foreach ($grant->resource?->fileList() ?? [] as $file) {
            $name = $file['group'];
            $index = $name ?? "\0";

            $groups[$index] ??= ['name' => $name, 'files' => []];

            $expiresAt = $this->expiry($grant, null);

            if ($ceiling !== null && $ceiling->lt($expiresAt)) {
                $expiresAt = $ceiling;
            }

            $groups[$index]['files'][] = [
                'key' => $file['key'],
                'label' => $file['label'] ?? basename($file['path']),
                'url' => $this->forFile($grant, $file['key'], $expiresAt),
            ];
        }

        return array_values($groups);
    }

    protected function expiry(Grant $grant, ?Carbon $expiresAt): Carbon
    {
        $resource = $grant->resource;

        $expiresAt ??= Carbon::now()->addMinutes(
            $resource?->linkTtlMinutes() ?? (int) config('lead-magnets.delivery.link_ttl', 10080)
        );

        // The grant's own lifetime is a ceiling on the link's. Without this a
        // 7-day link handed out on the last day of a grant would outlive the
        // access it grants — the controller would refuse it anyway, but a
        // link that is valid and refused is the worst of both.
        //
        // Read from the entitlement, and from whichever of its two dates is
        // actually holding the door open: a grant inside a grace period has an
        // `expires_at` in the past and access all the same.
        $endsAt = $grant->accessEndsAt();

        if ($endsAt !== null && $endsAt->lt($expiresAt)) {
            $expiresAt = Carbon::instance($endsAt);
        }

        return $expiresAt;
    }
}
