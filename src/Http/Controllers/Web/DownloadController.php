<?php

namespace Goldnead\LeadMagnets\Http\Controllers\Web;

use Goldnead\LeadMagnets\Events\ResourceDownloaded;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Services\GrantService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
    public function __invoke(Request $request, int $grant, GrantService $grants)
    {
        $record = Grant::query()->find($grant);

        abort_unless($record !== null && $record->isRedeemable(), 403);

        // Fetched by key rather than through the relation, so a grant whose
        // resource was deleted out from under it answers 404 instead of
        // dereferencing null. The relation's type says it cannot happen; a
        // route that serves files is not the place to take that on trust.
        $resource = Resource::query()->find($record->resource_id);

        abort_if($resource === null, 404);

        $record->setRelation('resource', $resource);

        $download = $grants->recordDownload($record, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        ResourceDownloaded::dispatch($record, $download);

        if ($resource->isLink()) {
            abort_if(! $resource->link_url, 404);

            // Counted first, then forwarded: a redirect that is not audited is
            // a delivery nobody can prove happened.
            return redirect()->away($resource->link_url);
        }

        $disk = Storage::disk($resource->disk());

        abort_unless($resource->file_path && $disk->exists($resource->file_path), 404);

        return $disk->download(
            $resource->file_path,
            $this->filename($resource->title, $resource->file_path),
        );
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
