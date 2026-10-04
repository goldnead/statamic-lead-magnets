<?php

namespace Goldnead\LeadMagnets\Http\Controllers\Web;

use Goldnead\LeadMagnets\Events\ResourceDownloaded;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Services\DownloadLink;
use Goldnead\LeadMagnets\Services\GrantService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * The only route that serves the file.
 *
 * Four gates, in this order, and each of them is a test:
 *
 * 1. The signature. `signed` middleware on the route answers 403 for an
 *    expired link and for a tampered one before this method runs — a changed
 *    grant id, a moved expiry or an extra parameter all break the hash.
 * 2. The grant exists and is redeemable: active, not lapsed, not over its
 *    download cap. A revoked grant holds a link that is still cryptographically
 *    valid, and it must not serve — the signature proves the link was issued,
 *    not that the access still stands.
 * 3. The resource still has something to serve.
 * 4. Only then is the redemption counted and the file streamed.
 *
 * Every refusal is a 403 or a 404 with no body worth reading. Distinguishing
 * "revoked" from "expired" from "never existed" for an unauthenticated caller
 * would turn this route into an oracle over who holds which resource.
 */
class DownloadController extends Controller
{
    public function __invoke(Request $request, int $grant, GrantService $grants, DownloadLink $links, ?string $file = null)
    {
        // The entitlement comes with it: `isRedeemable()` asks it for the state,
        // and a lazy load here would be a second query on the hot path of the
        // only route that serves a file.
        $record = Grant::query()->with('entitlement')->find($grant);

        abort_unless($record !== null && $record->isRedeemable(), 403);

        // Fetched by key rather than through the relation, so a grant whose
        // resource was deleted out from under it answers 404 instead of
        // dereferencing null. The relation's type says it cannot happen; a
        // route that serves files is not the place to take that on trust.
        $resource = Resource::query()->find($record->resource_id);

        abort_if($resource === null, 404);

        $record->setRelation('resource', $resource);

        // A resource with a list of files. The plain link is its overview
        // page, which hands out one signed link per file and counts nothing:
        // opening a page is not a download. A link naming a file is served
        // below, counted per file.
        if ($file === null && $resource->hasMultipleFiles()) {
            // The page's own deadline caps the links on it. Without that, a
            // page opened five minutes before its link expires would sign
            // fresh links that run for days — the page link would be a way to
            // renew itself.
            $ceiling = Carbon::createFromTimestamp((int) $request->query('expires'));

            return response()->view('lead-magnets::download', [
                'grant' => $record,
                'resource' => $resource,
                'groups' => $links->groupedFor($record, $ceiling),
            ]);
        }

        $entry = null;

        if ($file !== null) {
            $entry = $resource->findFile($file);

            abort_if($entry === null, 404);

            $disk = Storage::disk($resource->disk());

            abort_unless($disk->exists($entry['path']), 404);

            // The cap is per file for a list. The grant-level counter would
            // lock a reader out of eight files after downloading three.
            abort_if($resource->hasMultipleFiles() && $record->downloadsExhaustedFor($entry['key']), 403);
        }

        $download = $grants->recordDownload($record, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'file_key' => $resource->hasMultipleFiles() ? $entry['key'] : null,
        ]);

        ResourceDownloaded::dispatch($record, $download);

        if ($resource->isLink()) {
            abort_if(! $resource->link_url, 404);

            // Counted first, then forwarded: a redirect that is not audited is
            // a delivery nobody can prove happened.
            return redirect()->away($resource->link_url);
        }

        $disk = Storage::disk($resource->disk());

        if ($entry !== null && $resource->hasMultipleFiles()) {
            // A list's files are named by their label, because "Partitur" with
            // the voicing in the group is what the reader knows it as; the
            // extension still comes from the path.
            return $disk->download(
                $entry['path'],
                $this->filename($entry['label'] ?? pathinfo($entry['path'], PATHINFO_FILENAME), $entry['path']),
            );
        }

        $path = $entry['path'] ?? $resource->file_path;

        abort_unless($path && $disk->exists($path), 404);

        return $disk->download($path, $this->filename($resource->title, $path));
    }

    /**
     * A readable filename, derived from the title rather than the storage path.
     *
     * The path is an implementation detail and often a hash; the title is what
     * the reader asked for. The extension still comes from the path, because
     * that is the only place it is true.
     */
    protected function filename(string $title, string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base = trim(preg_replace('/[^\pL\pN\-_ ]+/u', '', $title) ?? '') ?: 'download';

        return $extension ? $base.'.'.$extension : $base;
    }
}
