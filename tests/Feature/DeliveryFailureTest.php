<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\LeadMagnets\Contracts\SenderIdentityResolver;
use Goldnead\LeadMagnets\LeadMagnetsManager;
use Goldnead\LeadMagnets\Mail\DeliveryMail;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Sending\BrandMailer;
use Goldnead\LeadMagnets\Services\GrantService;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\User;

/*
 * A confirmation spends the token and activates the grant before the delivery
 * mail goes out. When that mail fails, the visitor cannot click again (the
 * token is gone), so the failure has to stay on the grant where an editor can
 * see it and press "send again". Likewise a revoke that did not happen must not
 * be reported as done.
 */

it('keeps the grant active and records the failure when the delivery mail throws', function () {
    $token = (function () {
        makeResource(['handle' => 'warm_up']);
        test()->post(route('lead-magnets.request'), ['email' => 'reader@example.com', 'resource' => 'warm_up']);

        return tokenFromLastConfirmationMail();
    })();

    // Only the delivery mail fails; the confirmation went out before.
    app()->instance(BrandMailer::class, new class(app(SenderIdentityResolver::class)) extends BrandMailer
    {
        public function send(?int $brandId, string $to, ?string $toName, Mailable $mailable): bool
        {
            if ($mailable instanceof DeliveryMail) {
                throw new RuntimeException('smtp down');
            }

            return parent::send($brandId, $to, $toName, $mailable);
        }
    });

    $this->get(route('lead-magnets.confirm', ['token' => $token]))->assertOk();

    $grant = Grant::query()->with('entitlement')->sole();

    expect($grant->state())->toBe(EntitlementState::Active)
        ->and($grant->delivered_at)->toBeNull()
        ->and($grant->meta['last_hold'] ?? null)->toBe('delivery_failed');
});

it('keeps a repeat request from throwing when the re-send mail fails', function () {
    makeResource(['handle' => 'warm_up', 'requires_confirmation' => false]);

    // First request activates the grant; the mailer works.
    test()->post(route('lead-magnets.request'), ['email' => 'reader@example.com', 'resource' => 'warm_up']);
    $grant = Grant::query()->sole();

    app()->instance(BrandMailer::class, new class(app(SenderIdentityResolver::class)) extends BrandMailer
    {
        public function send(?int $brandId, string $to, ?string $toName, Mailable $mailable): bool
        {
            if ($mailable instanceof DeliveryMail) {
                throw new RuntimeException('smtp down');
            }

            return parent::send($brandId, $to, $toName, $mailable);
        }
    });

    // The repeat request re-sends the file. A mailer failure there must not
    // surface as a 500 to the visitor; it is held on the grant like on confirm.
    $this->withoutExceptionHandling();
    $returned = app(LeadMagnetsManager::class)
        ->request($grant->resource, 'reader@example.com');

    expect($returned->id)->toBe($grant->id)
        ->and($grant->refresh()->meta['last_hold'] ?? null)->toBe('delivery_failed');
});

it('does not report a revoke as done when the grant has no entitlement', function () {
    Gate::before(fn () => true);
    $editor = User::make()->email('editor@example.com');
    $editor->save();
    $this->actingAs($editor);

    $resource = makeResource(['requires_confirmation' => false]);
    app(GrantService::class)->request($resource, 'reader@example.com');
    $grant = Grant::query()->with('entitlement')->sole();
    $grant->entitlement->delete();

    $this->post(cp_route('lead-magnets.grants.revoke', $grant->id))
        ->assertSessionHasErrors('grant')
        ->assertSessionMissing('success');

    $this->post(cp_route('lead-magnets.grants.reinstate', $grant->id))
        ->assertSessionHasErrors('grant')
        ->assertSessionMissing('success');
});
