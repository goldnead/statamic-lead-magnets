<?php

use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\LeadMagnets\Integrations\Map\LeadMagnetMapObjects;
use Goldnead\LeadMagnets\Models\Resource;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\User;

/*
 * The page a lead magnet's sign-up form stands on. The column is only a pointer
 * to a Statamic entry; the map draws the edge from it and nothing else reads it.
 */

describe('Control Panel page field', function (): void {
    beforeEach(function (): void {
        Gate::before(fn () => true);

        $manager = User::make()->email('cp-page@example.com');
        $manager->save();

        $this->actingAs($manager);
    });

    it('saves the page entry on create and on update, and clears it when empty', function (): void {
        $this->post(cp_route('lead-magnets.resources.store'), [
            'title' => 'Atemübungen',
            'delivery_type' => 'link',
            'link_url' => 'https://example.com',
            'page_entry' => '  entry-123  ',
        ])->assertRedirect();

        $resource = Resource::query()->sole();

        expect($resource->page_entry)->toBe('entry-123');

        $this->patch(cp_route('lead-magnets.resources.update', $resource->id), [
            'title' => 'Atemübungen',
            'delivery_type' => 'link',
            'link_url' => 'https://example.com',
            'page_entry' => 'entry-456',
        ])->assertRedirect();

        expect($resource->refresh()->page_entry)->toBe('entry-456');

        $this->patch(cp_route('lead-magnets.resources.update', $resource->id), [
            'title' => 'Atemübungen',
            'delivery_type' => 'link',
            'link_url' => 'https://example.com',
            'page_entry' => '',
        ])->assertRedirect();

        expect($resource->refresh()->page_entry)->toBeNull();
    });

    it('accepts 191 characters and refuses 192', function (int $length, bool $accepted): void {
        $response = $this->post(cp_route('lead-magnets.resources.store'), [
            'title' => 'Länge',
            'delivery_type' => 'link',
            'link_url' => 'https://example.com',
            'page_entry' => str_repeat('a', $length),
        ]);

        if ($accepted) {
            $response->assertRedirect();

            expect(Resource::query()->sole()->page_entry)->toHaveLength(191);
        } else {
            $response->assertSessionHasErrors('page_entry');

            expect(Resource::query()->count())->toBe(0);
        }
    })->with([[191, true], [192, false]]);
});

describe('Map edges from the page', function (): void {
    it('draws one edge from the page to the lead magnet when the page is set', function (): void {
        makeResource([
            'handle' => 'atmung',
            'title' => 'Atemübungen',
            'delivery_type' => Resource::TYPE_LINK,
            'file_path' => null,
            'link_url' => 'https://example.test/atmung',
            'page_entry' => 'seite-atmung',
        ]);

        $edges = iterator_to_array(app(LeadMagnetMapObjects::class)->edges(), false);

        expect($edges)->toHaveCount(1)
            ->and($edges[0]->fromType)->toBe('entry')
            ->and($edges[0]->fromId)->toBe('seite-atmung')
            ->and($edges[0]->toType)->toBe('lead_magnet')
            ->and($edges[0]->toId)->toBe('atmung')
            ->and($edges[0]->kind)->toBe('collects')
            ->and($edges[0]->label)->toBe('collects sign-ups')
            ->and($edges[0]->derivedFrom)->toBe('field `page` of the lead magnet')
            ->and($edges[0]->dashed)->toBeFalse();
    });

    it('draws no edge for a resource without a page', function (): void {
        makeResource([
            'handle' => 'ohne_seite',
            'delivery_type' => Resource::TYPE_LINK,
            'file_path' => null,
            'link_url' => 'https://example.test/ohne',
            'page_entry' => null,
        ]);

        expect(iterator_to_array(app(LeadMagnetMapObjects::class)->edges(), false))->toBe([]);
    });

    it('hands the edge to the map registry', function (): void {
        makeResource([
            'handle' => 'atmung',
            'delivery_type' => Resource::TYPE_LINK,
            'file_path' => null,
            'link_url' => 'https://example.test/atmung',
            'page_entry' => 'seite-atmung',
        ]);

        $edges = app(MapObjectRegistry::class)->edges();

        expect($edges)->toHaveKey('entry:seite-atmung>collects>lead_magnet:atmung')
            ->and($edges['entry:seite-atmung>collects>lead_magnet:atmung']->label)->toBe('collects sign-ups');
    });
});
