<?php

namespace Goldnead\LeadMagnets\Http\Controllers\Cp;

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Support\LeadMagnetSubject;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\CP\Column;
use Statamic\Support\Str;

class ResourceController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeOrFail($request, 'view lead magnets');

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
                    'requires_confirmation' => $resource->requires_confirmation,
                    'published' => $resource->published,
                    'active' => (int) ($active[$resource->id] ?? 0),
                    'pending' => (int) ($pending[$resource->id] ?? 0),
                    'show_url' => cp_route('lead-magnets.resources.show', $resource->id),
                    'edit_url' => cp_route('lead-magnets.resources.edit', $resource->id),
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

        return Inertia::render('lead-magnets::Resources/Edit', [
            'resource' => null,
            'storeUrl' => cp_route('lead-magnets.resources.store'),
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

        $record = Resource::query()->find($resource);
        abort_if($record === null, 404);

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
            // Only the four fields the page renders. Handing the whole model —
            // let alone the addon's config — to Inertia would put the storage
            // disk and the file path into the page source of every editor's
            // browser for no reason.
            'resource' => [
                'id' => $record->id,
                'handle' => $record->handle,
                'title' => $record->title,
                'description' => $record->description,
                'delivery_type' => $record->delivery_type,
                'requires_confirmation' => $record->requires_confirmation,
                'published' => $record->published,
            ],
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
            'editUrl' => cp_route('lead-magnets.resources.edit', $record->id),
            'canManage' => $this->userCan($request, 'manage lead magnets'),
            'canManageGrants' => $this->userCan($request, 'manage lead magnet grants'),
        ]);
    }

    public function edit(Request $request, int $resource)
    {
        $this->authorizeOrFail($request, 'manage lead magnets');

        $record = Resource::query()->find($resource);
        abort_if($record === null, 404);

        return Inertia::render('lead-magnets::Resources/Edit', [
            'resource' => [
                'id' => $record->id,
                'handle' => $record->handle,
                'title' => $record->title,
                'description' => $record->description,
                'delivery_type' => $record->delivery_type,
                'file_path' => $record->file_path,
                'file_disk' => $record->file_disk,
                'link_url' => $record->link_url,
                'requires_confirmation' => $record->requires_confirmation,
                'published' => $record->published,
                'link_ttl' => $record->link_ttl,
                'max_downloads' => $record->max_downloads,
                'grant_ttl_days' => $record->grant_ttl_days,
                'tags' => $record->tagList(),
                'marketing_list' => $record->marketing_list,
            ],
            'updateUrl' => cp_route('lead-magnets.resources.update', $record->id),
            'deleteUrl' => cp_route('lead-magnets.resources.destroy', $record->id),
        ]);
    }

    public function update(Request $request, int $resource)
    {
        $this->authorizeOrFail($request, 'manage lead magnets');

        $record = Resource::query()->find($resource);
        abort_if($record === null, 404);

        $record->update($this->attributes($this->validated($request, $record)));

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
            'file_path' => ['nullable', 'string', 'max:255', 'required_if:delivery_type,file'],
            'file_disk' => ['nullable', 'string', 'max:64'],
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
    protected function attributes(array $data): array
    {
        return [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'delivery_type' => $data['delivery_type'],
            'file_path' => $data['delivery_type'] === Resource::TYPE_FILE ? ($data['file_path'] ?? null) : null,
            'file_disk' => $data['delivery_type'] === Resource::TYPE_FILE ? ($data['file_disk'] ?? null) : null,
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
