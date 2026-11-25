<?php

use Goldnead\LeadMagnets\Integrations\LeadhubBridge;
use Goldnead\LeadMagnets\Integrations\SiblingBridges;
use Goldnead\LeadMagnets\Integrations\SuppressionBridge;
use Goldnead\LeadMagnets\LeadMagnetsManager;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Services\DeliveryService;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeLeadHubFacade;
use Goldnead\LeadMagnets\Tests\Fixtures\FakeSuppressionGate;
use Goldnead\LeadMagnets\Tests\Fixtures\SiblingStubs;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;

/*
 * Stille Fehler, die jetzt im Log stehen: fehlende Methode am Sibling, Kontakt
 * ohne id, und ein Hold, der nach erfolgreicher Zustellung nicht mehr stimmt.
 */

class FakeLeadHubWithoutIdRoot
{
    public function findByEmail(string $email): ?array
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function create(array $attributes): array
    {
        return [];
    }
}

class FakeLeadHubWithoutIdFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FakeLeadHubWithoutIdRoot::class;
    }
}

beforeEach(function () {
    SiblingStubs::bindAll();

    app()->forgetInstance(SiblingBridges::class);
    app(SiblingBridges::class)->boot(app('events'));
});

it('warns in the log when the suppression gate has no isSuppressed() and lets the send through', function () {
    Log::spy();

    $bridge = new class extends SuppressionBridge
    {
        protected function facade(): string
        {
            // Has findByEmail(), not isSuppressed(): the gate is there, the method is not.
            return FakeLeadHubFacade::class;
        }
    };

    expect($bridge->blocks('reader@example.com'))->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'isSuppressed'))
        ->once();
});

it('takes last_hold off the grant once the delivery goes through', function () {
    FakeSuppressionGate::$suppressed = ['bounced@example.com'];

    $resource = makeResource(['requires_confirmation' => false]);

    app(LeadMagnetsManager::class)->request($resource, 'bounced@example.com');

    expect(Grant::query()->sole()->meta['last_hold'])->toBe('delivery_suppressed');

    FakeSuppressionGate::$suppressed = [];

    $grant = Grant::query()->with('resource')->sole();

    expect(app(DeliveryService::class)->deliver($grant))->toBeTrue();

    $meta = $grant->fresh()->meta;

    expect($meta)->not->toHaveKey('last_hold')
        ->and($meta)->not->toHaveKey('last_hold_at');
});

it('warns in the log when leadhub answers a contact without an id', function () {
    Log::spy();

    $bridge = new class extends LeadhubBridge
    {
        protected function facade(): string
        {
            return FakeLeadHubWithoutIdFacade::class;
        }
    };

    $grant = new Grant(['email' => 'reader@example.com']);

    expect($bridge->onActivated($grant))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'leadhub') && str_contains($message, 'id'))
        ->once();
});
