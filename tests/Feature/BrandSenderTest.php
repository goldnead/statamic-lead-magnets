<?php

use Goldnead\BrandContext\Sending\SenderIdentity;
use Goldnead\LeadMagnets\Contracts\SenderIdentityResolver;
use Goldnead\LeadMagnets\Services\DeliveryService;
use Goldnead\LeadMagnets\Services\GrantService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Absenderidentität je Marke (2026-08-24)
|--------------------------------------------------------------------------
|
| Beide Mails hier gehen an ein Mitglied der Öffentlichkeit, das gerade seine
| Adresse hergegeben hat. Eine, die unter dem Namen einer fremden Marke
| ankommt, bittet den Leser, einem Absender zu vertrauen, von dem er nie
| gehört hat — und das Relay der fremden Marke lehnt sie womöglich ganz ab.
|
*/

function bindSenderIdentity(SenderIdentity $identity): void
{
    app()->bind(SenderIdentityResolver::class, fn () => new class($identity) implements SenderIdentityResolver
    {
        public function __construct(private SenderIdentity $identity) {}

        public function resolve(?int $brandId): SenderIdentity
        {
            return $this->identity;
        }
    });
}

function captureFrom(): object
{
    $seen = new class
    {
        public ?string $from = null;
    };

    // Mail::fake() records mailables but swallows the send, so the assembled
    // From — the only thing these tests are about — is never observable.
    Event::listen(MessageSending::class, function (MessageSending $event) use ($seen): void {
        $addresses = $event->message->getFrom();
        $seen->from = $addresses === [] ? null : $addresses[0]->getAddress();
    });

    return $seen;
}

it('sends the delivery mail under the brand identity', function () {
    $seen = captureFrom();
    bindSenderIdentity(SenderIdentity::of(null, 'marke@example.com', 'Marke', null));

    $resource = makeResource();
    $grant = makeGrant($resource, 'leser@example.com');

    expect(app(DeliveryService::class)->deliver($grant))->toBeTrue();
    expect($seen->from)->toBe('marke@example.com');
});

it('sends the confirmation mail under the brand identity', function () {
    $seen = captureFrom();
    bindSenderIdentity(SenderIdentity::of(null, 'marke@example.com', 'Marke', null));

    $resource = makeResource();

    // Straight from the service: the plain token only exists on the object it
    // returns. makeGrant() re-reads from the database, and sendConfirmation()
    // refuses without a token — so going through it would test nothing.
    $grant = app(GrantService::class)->request($resource, 'leser@example.com');

    expect(app(DeliveryService::class)->sendConfirmation($grant))->toBeTrue();

    expect($seen->from)->toBe('marke@example.com');
});

it('refuses to deliver rather than send under the wrong name', function () {
    $seen = captureFrom();
    bindSenderIdentity(SenderIdentity::refusing('Brand declares no from_address.'));

    $resource = makeResource();
    $grant = makeGrant($resource, 'leser@example.com');

    // Not sending is the right answer here. The alternative is a stranger's
    // download arriving under a name that is not the one they trusted.
    expect(app(DeliveryService::class)->deliver($grant))->toBeFalse();
    expect($seen->from)->toBeNull();

    // And the reason is recorded on the grant, so "they never got it" has a
    // cause attached instead of being a mystery.
    expect($grant->fresh()->meta['last_hold'] ?? null)->toBe('delivery_sender_refused');
});

it('leaves a single-brand install sending exactly as before', function () {
    $seen = captureFrom();

    // fromConfig() carries no address, which is what an install without brands
    // resolves to. The mailable's own From then decides, as it always did.
    bindSenderIdentity(SenderIdentity::fromConfig());

    $resource = makeResource();
    $grant = makeGrant($resource, 'leser@example.com');

    expect(app(DeliveryService::class)->deliver($grant))->toBeTrue();
    expect($seen->from)->toBe(config('mail.from.address'));
});
