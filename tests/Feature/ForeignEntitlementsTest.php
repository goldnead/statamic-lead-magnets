<?php

use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Models\Entitlement;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\LeadMagnets\Events\ResourceDelivered;
use Goldnead\LeadMagnets\Support\LeadMagnetSubject;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\User;

/*
 * The entitlements table is shared. A payment webhook, a manual grant from the
 * entitlements Control Panel and this addon all write into it, and more than one
 * of them may legitimately name the same product slug — a resource offered free
 * behind an opt-in and also included in a paid bundle is an ordinary thing.
 *
 * So every place this addon reaches into that table filters on its own `source`.
 * Two of those places would do real damage if the filter were dropped: the
 * delivery listener would mail a download link to somebody who bought something
 * else, and deleting a resource would delete a customer's purchase.
 */

it('does not deliver for an entitlement another writer confirmed', function () {
    Event::fake([ResourceDelivered::class]);

    makeResource(['handle' => 'warm_up']);

    // Same product slug, same subject, different source: a purchase, not a
    // lead-magnet claim.
    $foreign = Entitlements::grantPending(
        LeadMagnetSubject::for('buyer@example.com'),
        'warm_up',
        'mollie',
        'tr_12345',
    );

    $before = sentMailCount();

    expect(Entitlements::claimPending($foreign))->toBeTrue()
        ->and(sentMailCount())->toBe($before);

    Event::assertNotDispatched(ResourceDelivered::class);
});

it('does not deliver for an entitlement that has no grant behind it', function () {
    Event::fake([ResourceDelivered::class]);

    makeResource(['handle' => 'warm_up']);

    // Our source, our slug, but written by hand rather than through the request
    // flow — so there is no delivery record, no address and nothing to send.
    $orphan = Entitlements::grantPending(
        new SubjectReference('lead-magnet-contact', str_repeat('a', 64)),
        'warm_up',
        'lead_magnet',
        '99',
    );

    expect(Entitlements::claimPending($orphan))->toBeTrue();

    Event::assertNotDispatched(ResourceDelivered::class);
});

it('leaves another writer\'s entitlement alone when the resource is deleted', function () {
    Gate::before(fn () => true);

    $manager = User::make()->email('deleter@example.com');
    $manager->save();
    $this->actingAs($manager);

    $resource = makeResource(['requires_confirmation' => false]);

    makeGrant($resource, 'reader@example.com');

    $purchase = Entitlements::grant(
        LeadMagnetSubject::for('buyer@example.com'),
        $resource->handle,
        'mollie',
        'tr_67890',
    );

    $this->delete(cp_route('lead-magnets.resources.destroy', $resource->id))->assertRedirect();

    // The lead-magnet grant went with the resource. The purchase did not: this
    // addon does not own it, and deleting a marketing asset must not revoke
    // somebody's paid access.
    expect(Entitlement::query()->count())->toBe(1)
        ->and(Entitlement::query()->sole()->id)->toBe($purchase->id);
});
