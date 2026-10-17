<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\LeadMagnets\Integrations\LeadhubLinks;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeLeadHubFacade;
use Goldnead\LeadMagnets\Tests\Fixtures\SiblingStubs;
use Goldnead\LeadMagnets\Tests\Fixtures\StubbedLeadhubLinks;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\User;

/*
 * The "Access" table links an address to its LeadHub contact, if LeadHub is
 * there. Both worlds are covered: with the sibling (stand-in) and without it.
 */

beforeEach(function () {
    SiblingStubs::reset();
});

it('answers nothing for every row when leadhub is not installed', function () {
    // The real class: LeadHub is not installed in this suite, on purpose.
    $links = app(LeadhubLinks::class);

    expect($links->available())->toBeFalse()
        ->and($links->forGrants([['email' => 'reader@example.com', 'contact_id' => null]]))
        ->toBe([]);
});

it('links a known address to its contact', function () {
    FakeLeadHubFacade::$contacts['reader@example.com'] = ['id' => 7, 'uuid' => 'uuid-7', 'email' => 'reader@example.com'];

    $map = (new StubbedLeadhubLinks)->forGrants([
        ['email' => 'Reader@Example.com ', 'contact_id' => '7'],
    ]);

    expect($map)->toBe(['Reader@Example.com ' => ['state' => 'linked', 'url' => '/cp/leadhub/contacts/uuid-7']]);
});

it('leaves an address without contact as text', function () {
    $map = (new StubbedLeadhubLinks)->forGrants([['email' => 'nobody@example.com', 'contact_id' => null]]);

    expect($map)->toBe(['nobody@example.com' => ['state' => 'none', 'url' => null]]);
});

it('does not jump to a contact whose id differs from the one stored on the grant', function () {
    // The address now belongs to another contact than the one the grant was
    // synced to (address changed, contact merged): visibly ambiguous, no link.
    FakeLeadHubFacade::$contacts['reader@example.com'] = ['id' => 9, 'uuid' => 'uuid-9', 'email' => 'reader@example.com'];

    $map = (new StubbedLeadhubLinks)->forGrants([['email' => 'reader@example.com', 'contact_id' => '7']]);

    expect($map['reader@example.com'])->toBe(['state' => 'ambiguous', 'url' => null]);
});

it('hands out no link to a user without the contact permission', function () {
    FakeLeadHubFacade::$contacts['reader@example.com'] = ['id' => 7, 'uuid' => 'uuid-7', 'email' => 'reader@example.com'];

    $links = new StubbedLeadhubLinks;
    $links->mayView = false;

    expect($links->forGrants([['email' => 'reader@example.com', 'contact_id' => null]]))->toBe([]);
});

it('hands the link to the detail page, and null without leadhub', function () {
    Gate::before(fn () => true);
    $user = User::make()->email('links@example.com');
    $user->save();
    test()->actingAs($user);

    $resource = makeResource();
    makeGrant($resource, 'reader@example.com', EntitlementState::Active);

    // Without LeadHub: the real class, the cell stays text.
    test()->get(cp_route('lead-magnets.resources.show', $resource->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('grants.0.contact_link', null));

    // With the stand-in: state and url reach the page.
    FakeLeadHubFacade::$contacts['reader@example.com'] = ['id' => 7, 'uuid' => 'uuid-7', 'email' => 'reader@example.com'];
    app()->instance(LeadhubLinks::class, new StubbedLeadhubLinks);

    test()->get(cp_route('lead-magnets.resources.show', $resource->id))
        ->assertInertia(fn ($page) => $page
            ->where('grants.0.contact_link.state', 'linked')
            ->where('grants.0.contact_link.url', '/cp/leadhub/contacts/uuid-7'));
});

it('stops after the first failing lookup', function () {
    $links = new StubbedLeadhubLinks;
    $links->throws = true;

    FakeLeadHubFacade::$contacts['a@example.com'] = ['id' => 1, 'uuid' => 'u1', 'email' => 'a@example.com'];
    FakeLeadHubFacade::$contacts['b@example.com'] = ['id' => 2, 'uuid' => 'u2', 'email' => 'b@example.com'];

    expect($links->forGrants([
        ['email' => 'a@example.com', 'contact_id' => null],
        ['email' => 'b@example.com', 'contact_id' => null],
    ]))->toBe([]);
});

it('survives a leadhub that throws', function () {
    $links = new StubbedLeadhubLinks;
    $links->throws = true;

    FakeLeadHubFacade::$contacts['reader@example.com'] = ['id' => 7, 'uuid' => 'uuid-7', 'email' => 'reader@example.com'];

    expect($links->forGrants([['email' => 'reader@example.com', 'contact_id' => null]]))->toBe([]);
});
