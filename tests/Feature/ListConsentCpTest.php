<?php

use Goldnead\LeadMagnets\Models\Resource;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\User;

/*
 * The four new fields in the Control Panel: coupling, its disclosure, and the
 * two mail templates per resource. Coupling without a list, without a
 * confirmation or without a disclosure would promise the reader something the
 * flow then does not do, so the form refuses it rather than saving a switch
 * that silently has no effect.
 */

beforeEach(function () {
    Gate::before(fn () => true);

    $manager = User::make()->email('cp-consent@example.com');
    $manager->save();

    $this->actingAs($manager);
});

function linkResourcePayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Vowel Guide',
        'handle' => 'vowel_guide',
        'delivery_type' => 'link',
        'link_url' => 'https://example.com/vowel.pdf',
        'requires_confirmation' => true,
        'marketing_list' => 'newsletter',
    ], $overrides);
}

it('saves coupling, disclosure and both templates', function () {
    $this->post(cp_route('lead-magnets.resources.store'), linkResourcePayload([
        'list_via_confirmation' => true,
        'list_consent_text' => '  Mit der Anforderung meldest du dich zum Newsletter an.  ',
        'confirmation_template' => 'freebie-confirm',
        'delivery_template' => 'vowel-guide-welcome',
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $resource = Resource::query()->sole();

    expect($resource->list_via_confirmation)->toBeTrue()
        ->and($resource->list_consent_text)->toBe('Mit der Anforderung meldest du dich zum Newsletter an.')
        ->and($resource->confirmation_template)->toBe('freebie-confirm')
        ->and($resource->delivery_template)->toBe('vowel-guide-welcome')
        ->and($resource->couplesListToConfirmation())->toBeTrue();
});

it('refuses coupling without a disclosure, a list or a confirmation', function (array $overrides, string $field) {
    $this->post(cp_route('lead-magnets.resources.store'), linkResourcePayload(array_merge([
        'list_via_confirmation' => true,
        'list_consent_text' => 'Mit der Anforderung meldest du dich zum Newsletter an.',
    ], $overrides)))->assertSessionHasErrors($field);

    expect(Resource::query()->count())->toBe(0);
})->with([
    'no disclosure' => [['list_consent_text' => ''], 'list_consent_text'],
    'no list' => [['marketing_list' => ''], 'marketing_list'],
    'no confirmation' => [['requires_confirmation' => false], 'requires_confirmation'],
]);

it('hands the new fields to the edit form', function () {
    $resource = makeResource([
        'list_via_confirmation' => true,
        'list_consent_text' => 'Hinweis',
        'marketing_list' => 'newsletter',
        'delivery_template' => 'welcome',
    ]);

    $this->get(cp_route('lead-magnets.resources.show', $resource->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('resource.list_via_confirmation', true)
            ->where('resource.list_consent_text', 'Hinweis')
            ->where('resource.delivery_template', 'welcome')
            ->where('resource.confirmation_template', null)
        );
});
