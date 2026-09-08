<?php

namespace Goldnead\LeadMagnets\Http\Controllers\Cp;

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Support\LeadMagnetSubject;
use Goldnead\LeadMagnets\Support\MagnetAssets;
use Goldnead\LeadMagnets\Support\Setup;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\Assets\Asset;
use Statamic\CP\Column;
use Statamic\Facades\Asset as AssetFacade;
use Statamic\Facades\Blueprint;
use Statamic\Support\Str;

class ResourceController extends Controller
{
    public function __construct(protected MagnetAssets $assets) {}

    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view lead magnets');

        // `entitlements` belongs to a sibling addon and is listed anyway: the
        // two counts below are a join into it, so a half-migrated install would
        // crash on the foreign table just as readily as on our own.
        if ($setup = Setup::guard(__('lead-magnets::nav.lead_magnets'), 'lead_magnet_resources', 'lead_magnet_grants', 'entitlements')) {
            return $setup;
        }

        // Two queries for the whole page rather than one per resource. State is
        // not a column any more, so the count runs through entitlements' own
        // SQL projection of the resolver — the same expression the resolver
        // applies in PHP, never a second reading of the rules.
        $active = $this->countByResource(EntitlementState::Active);
        $pending = $this->countByResource(EntitlementState::Pending);

        $rows = Resource::query()
            ->orderBy('title')
            ->get()
            ->map(function (Resource $resource) use ($active, $pending) {
                return [
                    'id' => $resource->id,
                    'handle' => $resource->handle,
                    'title' => $resource->title,
                    'delivery_type' => $resource->delivery_type,
                    // What is actually delivered, next to the pill that says
                    // which of the two ways applies. A row may carry a value in
                    // both columns — nothing stops a seed or an older record —
                    // and `delivery_type` alone then reads as an assertion the
                    // data does not back. The download route branches on
                    // `delivery_type` and on nothing else, so naming the source
                    // it will actually reach for settles the question on screen.
                    'delivery_source' => $resource->isLink()
                        ? $resource->link_url
                        : ($resource->file_path === null ? null : basename($resource->file_path)),
                    'requires_confirmation' => $resource->requires_confirmation,
                    'published' => $resource->published,
                    'active' => (int) ($active[$resource->id] ?? 0),
                    'pending' => (int) ($pending[$resource->id] ?? 0),
                    'show_url' => cp_route('lead-magnets.resources.show', $resource->id),
                    'delete_url' => cp_route('lead-magnets.resources.destroy', $resource->id),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('lead-magnets::Resources/Index', [
            'resources' => $rows,
            'columns' => $this->columns(),
            'createUrl' => cp_route('lead-magnets.resources.create'),
            'canManage' => $this->userCan($request, 'manage lead magnets'),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeOrFail($request, 'manage lead magnets');

        return Inertia::render('lead-magnets::Resources/Create', [
            'storeUrl' => cp_route('lead-magnets.resources.store'),
            'fileField' => $this->fileField(null),
            'diskWarning' => $this->diskWarning(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeOrFail($request, 'manage lead magnets');

        $data = $this->validated($request, null);

        // `validate()` omits a nullable key that was not sent at all, so this
        // reads with `??` rather than `?:`.
        //
        // `Str::slug(…, '_')` rather than `Str::snake()`: snake leaves a
        // hyphen where the title had one ("Warm-up routine" → "warm-up_routine")
        // and transliterates nothing, so a German title comes out with an
        // umlaut in a handle that a URL then percent-encodes.
        $handle = ($data['handle'] ?? null) ?: Str::slug($data['title'], '_');

        if (Resource::query()->acrossBrands()->where('handle', $handle)->exists()) {
            return back()->withErrors(['handle' => __('lead-magnets::resources.handle_taken')]);
        }

        $resource = Resource::query()->create($this->attributes($data) + ['handle' => $handle]);

        return redirect()
            ->to(cp_route('lead-magnets.resources.show', $resource->id))
            ->with('success', __('lead-magnets::resources.created'));
    }

    public function show(Request $request, int $resource)
    {
        $this->authorizeOrFail($request, 'view lead magnets');

        // One table more than the listing: this page counts the downloads per
        // grant, and the guard is only worth anything if it names every table
        // the page will actually reach for.
        if ($setup = Setup::guard(__('lead-magnets::nav.lead_magnets'), 'lead_magnet_resources', 'lead_magnet_grants', 'lead_magnet_downloads', 'entitlements')) {
            return $setup;
        }

        $record = Resource::query()->find($resource);
        abort_if($record === null, 404);

        $canManage = $this->userCan($request, 'manage lead magnets');

        // An unknown filter value is dropped rather than passed to the resolver.
        // `EntitlementState::from()` on a query string is an uncaught ValueError
        // and a 500 for anybody who edits the URL.
        $state = EntitlementState::tryFrom((string) $request->input('state', ''));

        $page = Grant::query()
            ->where('resource_id', $record->id)
            ->with('entitlement')
            ->withCount('downloads')
            ->when($state !== null, fn ($query) => $query->inState($state))
            ->when($request->input('search'), fn ($query, $search) => $query->where('email', 'like', '%'.$search.'%'))
            ->orderByDesc('requested_at')
            ->paginate(50)
            ->withQueryString();

        $grants = collect($page->items())->map(fn (Grant $grant) => [
            'id' => $grant->id,
            'email' => $grant->email,
            'state' => $grant->stateValue(),
            'requested_at' => $grant->requested_at?->toIso8601String(),
            'confirmed_at' => $grant->confirmedAt()?->toIso8601String(),
            'delivered_at' => $grant->delivered_at?->toIso8601String(),
            'expires_at' => $grant->accessEndsAt()?->toIso8601String(),
            'downloads' => $grant->downloads_count,
            'lapsed' => $grant->hasLapsed(),
            'revoke_url' => cp_route('lead-magnets.grants.revoke', $grant->id),
            'reinstate_url' => cp_route('lead-magnets.grants.reinstate', $grant->id),
            'resend_url' => cp_route('lead-magnets.grants.resend', $grant->id),
        ])->all();

        return Inertia::render('lead-magnets::Resources/Show', [
            // The editable fields, and only those. The storage disk and the
            // path on it stay out: they are of no use to a form and would sit
            // in the page source of every editor's browser. The file picker
            // below carries the one path it needs, and only for somebody who
            // may change it.
            'resource' => [
                'id' => $record->id,
                'handle' => $record->handle,
                'title' => $record->title,
                'description' => $record->description,
                'delivery_type' => $record->delivery_type,
                'link_url' => $record->link_url,
                'requires_confirmation' => $record->requires_confirmation,
                'published' => $record->published,
                'link_ttl' => $record->link_ttl,
                'max_downloads' => $record->max_downloads,
                'grant_ttl_days' => $record->grant_ttl_days,
                'tags' => $record->tagList(),
                'marketing_list' => $record->marketing_list,
            ],
            // The detail page is the form — the same shape the create page
            // gets. Without the permission to change anything it is handed no
            // picker, no save route and no delete route, and the fields render
            // read-only; hiding a button would not be authorization, but there
            // is also no reason to ship a control nobody may use.
            'fileField' => $canManage ? $this->fileField($record) : null,
            'diskWarning' => $canManage ? $this->diskWarning() : null,
            'updateUrl' => $canManage ? cp_route('lead-magnets.resources.update', $record->id) : null,
            'deleteUrl' => $canManage ? cp_route('lead-magnets.resources.destroy', $record->id) : null,
            'grants' => $grants,
            'columns' => $this->grantColumns(),
            // All six entitlement states, not the four this addon writes. An
            // operator can put a grant into a grace period or give it a start
            // date from the entitlements screen, and a filter list that did not
            // offer those would hide rows the listing shows.
            'states' => array_map(fn (EntitlementState $case) => $case->value, EntitlementState::cases()),
            'filters' => ['state' => $state === null ? '' : $state->value, 'search' => (string) $request->input('search', '')],
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'canManage' => $canManage,
            'canManageGrants' => $this->userCan($request, 'manage lead magnet grants'),
        ]);
    }

    public function update(Request $request, int $resource)
    {
        $this->authorizeOrFail($request, 'manage lead magnets');

        $record = Resource::query()->find($resource);
        abort_if($record === null, 404);

        $record->update($this->attributes($this->validated($request, $record), $record));

        return back()->with('success', __('lead-magnets::resources.updated'));
    }

    public function destroy(Request $request, int $resource)
    {
        $this->authorizeOrFail($request, 'manage lead magnets');

        $record = Resource::query()->find($resource);
        abort_if($record === null, 404);

        // Grants, their audit rows and their entitlements go with it. Keeping
        // download records for a resource that no longer exists would leave an
        // audit nobody can read, and an entitlement for a product slug nothing
        // answers to is access to nothing — but it still shows up in the
        // entitlements listing as if it meant something.
        //
        // Only entitlements this addon wrote are removed. The `source` filter
        // is what keeps a purchase recorded by a payment webhook, which may
        // legitimately name the same slug, out of the deletion.
        Grant::query()->where('resource_id', $record->id)->each(function (Grant $grant) {
            $grant->downloads()->delete();
            $grant->delete();
        });

        // By slug, not by the current grants' ids: a grant whose window expired
        // and was reopened left earlier entitlements behind, and those name the
        // same slug and would otherwise outlive the resource.
        Entitlement::query()
            ->where('product_slug', $record->handle)
            ->where('source', LeadMagnetSubject::source())
            ->delete();

        $record->delete();

        return redirect()
            ->to(cp_route('lead-magnets.resources.index'))
            ->with('success', __('lead-magnets::resources.deleted'));
    }

    /**
     * The file picker: Statamic's own `assets` fieldtype over this addon's
     * container, as a one-field blueprint the Vue page renders through a
     * `PublishContainer`.
     *
     * The core fieldtype rather than a path to type by hand, because it is what
     * an editor already knows from every other screen — browsing, uploading,
     * renaming and the folder tree come with it, and none of them are worth
     * rebuilding. It is also the reason the container has to exist before this
     * page renders, so it is created here.
     *
     * The stored value stays what it always was, a path on a disk, because
     * that is what `DownloadController` streams. The fieldtype speaks asset ids
     * and lists; the conversion happens at this one seam and nowhere else.
     * A path that no longer resolves to an asset — a file deleted, or a magnet
     * whose path was set by hand before this existed — preprocesses to an empty
     * selection rather than an error.
     *
     * @return array{blueprint: array<string, mixed>, values: array<string, mixed>, meta: array<string, mixed>}
     */
    protected function fileField(?Resource $record): array
    {
        $this->assets->ensureContainer();

        $blueprint = Blueprint::makeFromFields([
            'file_asset' => [
                'type' => 'assets',
                'display' => __('lead-magnets::resources.file'),
                'instructions' => __('lead-magnets::resources.file_instructions'),
                'container' => $this->assets->containerHandle(),
                'max_files' => 1,
                'mode' => 'list',
            ],
        ]);

        $fields = $blueprint
            ->fields()
            ->addValues(['file_asset' => $record?->file_path ? [$record->file_path] : []])
            ->preProcess();

        return [
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values()->all(),
            'meta' => $fields->meta()->all(),
        ];
    }

    /**
     * The asset an id names, but only if it is one of this addon's.
     *
     * A trust boundary, not a formality: the id arrives from a browser and
     * decides which file a resource hands out. An id naming an asset in some
     * other container — the public one a site keeps its images in, say — is
     * refused rather than stored under this addon's disk, where it would
     * resolve to nothing and the download would 404 with no explanation.
     */
    protected function assetInContainer(mixed $id): ?Asset
    {
        if (! is_string($id) || $id === '') {
            return null;
        }

        $asset = AssetFacade::find($id);

        return $asset instanceof Asset && $asset->container()?->handle() === $this->assets->containerHandle()
            ? $asset
            : null;
    }

    /**
     * Said out loud on the form when the container's disk can be reached from
     * the web, because then the addon's central promise does not hold and
     * nothing else on the screen would show it.
     */
    protected function diskWarning(): ?string
    {
        return $this->assets->diskIsPublic()
            ? __('lead-magnets::resources.disk_public', ['disk' => $this->assets->diskHandle()])
            : null;
    }

    /**
     * How many grants per resource currently resolve to `$state`.
     *
     * @return array<int, int>
     */
    protected function countByResource(EntitlementState $state): array
    {
        return Grant::query()
            ->inState($state)
            ->selectRaw('resource_id, count(*) as aggregate')
            ->groupBy('resource_id')
            ->pluck('aggregate', 'resource_id')
            ->all();
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Resource $existing): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'handle' => [$existing ? 'prohibited' : 'nullable', 'string', 'max:191', 'regex:/^[a-z0-9_\-]+$/'],
            'description' => ['nullable', 'string'],
            'delivery_type' => ['required', 'in:file,link'],
            // The picker sends an asset id. It is required only when the record
            // has no file yet: an existing one keeps the file it has when the
            // selection comes back empty, so a title can be edited without
            // re-uploading (see `attributes()`).
            'file_asset' => [
                'nullable',
                'string',
                'max:255',
                $existing?->file_path === null ? 'required_if:delivery_type,file' : 'sometimes',
                // A trust boundary, not a formality. The id arrives from the
                // browser and decides which file this resource hands out, so an
                // id naming an asset in some other container — the public one a
                // site keeps its images in, say — is refused rather than stored
                // under this addon's disk, where it would resolve to nothing.
                function (string $attribute, mixed $value, callable $fail): void {
                    if ($value !== null && $value !== '' && $this->assetInContainer($value) === null) {
                        $fail(__('lead-magnets::resources.file_unknown'));
                    }
                },
            ],
            'link_url' => ['nullable', 'url', 'max:2000', 'required_if:delivery_type,link'],
            'requires_confirmation' => ['nullable', 'boolean'],
            'published' => ['nullable', 'boolean'],
            'link_ttl' => ['nullable', 'integer', 'min:1', 'max:525600'],
            'max_downloads' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'grant_ttl_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:191'],
            'marketing_list' => ['nullable', 'string', 'max:191'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function attributes(array $data, ?Resource $existing = null): array
    {
        $isFile = $data['delivery_type'] === Resource::TYPE_FILE;

        // The picker's asset id back to the path the download route streams.
        // Validation has already established that the asset exists and belongs
        // to this addon's container, so the disk is the container's by
        // construction rather than by something a form sent.
        $picked = $isFile
            ? $this->assetInContainer($data['file_asset'] ?? null)?->path()
            : null;

        // An empty selection on an existing record does not clear its file.
        // A resource whose path was set by hand before the picker existed
        // points outside the container, so the picker cannot show it and comes
        // back empty through no fault of the editor — emptying the column on
        // the save of an unrelated field would break a working download in
        // silence. Changing the file means choosing another one.
        $keep = $isFile && $picked === null && $existing?->file_path !== null;

        return [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'delivery_type' => $data['delivery_type'],
            'file_path' => $keep ? $existing->file_path : $picked,
            'file_disk' => $keep ? $existing->file_disk : ($picked === null ? null : $this->assets->diskHandle()),
            'link_url' => $data['delivery_type'] === Resource::TYPE_LINK ? ($data['link_url'] ?? null) : null,
            'requires_confirmation' => (bool) ($data['requires_confirmation'] ?? true),
            'published' => (bool) ($data['published'] ?? true),
            'link_ttl' => $data['link_ttl'] ?? null,
            'max_downloads' => $data['max_downloads'] ?? null,
            'grant_ttl_days' => $data['grant_ttl_days'] ?? null,
            'tags' => $data['tags'] ?? [],
            'marketing_list' => $data['marketing_list'] ?? null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function columns(): array
    {
        return collect([
            Column::make('title')->label(__('lead-magnets::resources.title')),
            Column::make('handle')->label(__('lead-magnets::resources.handle')),
            Column::make('delivery_type')->label(__('lead-magnets::resources.delivery_type')),
            Column::make('requires_confirmation')->label(__('lead-magnets::resources.requires_confirmation')),
            Column::make('active')->label(__('lead-magnets::grants.active')),
            Column::make('pending')->label(__('lead-magnets::grants.pending')),
            Column::make('published')->label(__('lead-magnets::resources.published')),
        ])->map(fn (Column $column) => $column->toArray())->all();
    }

    /** @return array<int, array<string, mixed>> */
    protected function grantColumns(): array
    {
        return collect([
            Column::make('email')->label(__('lead-magnets::grants.email')),
            Column::make('state')->label(__('lead-magnets::grants.state')),
            Column::make('requested_at')->label(__('lead-magnets::grants.requested_at')),
            Column::make('confirmed_at')->label(__('lead-magnets::grants.confirmed_at')),
            Column::make('downloads')->label(__('lead-magnets::grants.downloads')),
        ])->map(fn (Column $column) => $column->toArray())->all();
    }
}
