<?php

namespace Goldnead\LeadMagnets\Integrations\Map;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\LeadMagnets\Integrations\AutomationsBridge;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Services\DeliveryService;

/**
 * What a lead magnet would do in a dry run. Part of {@see LeadMagnetMapObjects}: the
 * map registry finds these methods with `method_exists`, so they live on the provider.
 *
 * Nothing is sent, queued, written or granted. The resource is read and the steps are
 * described as effects. The rules follow the code they describe: a request to an
 * unpublished magnet is answered with a 404 (RequestController::store), and a person who
 * already holds the access gets the delivery mail again and no confirmation
 * (GrantService::request()).
 *
 * @phpstan-require-extends LeadMagnetMapObjects
 */
trait SimulatesLeadMagnet
{
    /** Where a path can begin: a visitor asks for the lead magnet. */
    public function paths(string $id): array
    {
        return $this->resolve($id) ? [new SimEvent(SimEvent::REQUESTED)] : [];
    }

    /** The dry run shows mail subjects, so it is as guarded as the panel details. */
    public function simulationAuthorized(string $id, $user): bool
    {
        return $this->detailsAuthorized($id, $user);
    }

    /**
     * The access a person is given through this lead magnet, as the name of the grant (what a step that asks "did she
     * just unlock something" gets to read: its `product_slug`) and the title she knows it by. Null for a lead magnet
     * that gives no access. The dry run offers these as "came through which freebie".
     *
     * @return array{value: string, label: string}|null
     */
    public function simulationFreebie(string $id): ?array
    {
        $resource = $this->resolve($id);

        if ($resource === null || ! $resource->isAccess() || trim((string) $resource->access_product) === '') {
            return null;
        }

        return ['value' => trim((string) $resource->access_product), 'label' => trim((string) $resource->title) !== '' ? trim((string) $resource->title) : (string) $resource->handle];
    }

    /** @return list<Effect> */
    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        $resource = $this->resolve($id);

        if ($resource === null) {
            return [Effect::unknown(__('lead-magnets::resources.sim_missing'))];
        }

        if (! $resource->published) {
            return [Effect::stop(__('lead-magnets::resources.sim_unpublished'))];
        }

        if ($person->isKnown() && $person->hasAccess((string) $resource->handle)) {
            return [Effect::mail($this->deliverySubject($resource), __('lead-magnets::resources.sim_delivery_again_detail'), 0, ['kind' => 'delivery'])];
        }

        $line = [];

        if ($resource->requires_confirmation) {
            $line[] = Effect::mail(
                __('lead-magnets::mail.confirmation_subject', ['title' => $resource->title]),
                __('lead-magnets::resources.sim_confirmation_detail'),
                0,
                ['kind' => 'confirmation'],
            );
            $line[] = new Effect(
                Effect::WAIT,
                __('lead-magnets::resources.sim_wait_confirm'),
                __('lead-magnets::resources.sim_wait_confirm_detail'),
                0,
                ['duration_known' => false],
            );
        }

        if ($resource->isAccess()) {
            // Access first, the mail after it (DeliveryService::deliverAccess): the link in the mail needs the
            // access to stand. A mail series that is switched on and that this person has not been through
            // takes the delivery over: its first mail goes out instead of the delivery mail, and it starts
            // from the access that was just granted (the connection to its automation does that).
            $product = trim((string) $resource->access_product);
            $series = $resource->seriesHandle();
            $takesOver = $series !== null
                && app(AutomationsBridge::class)->isLive($series) === true
                && ! ($person->isKnown() && $person->hasRun($series));

            $line[] = Effect::access(
                __('lead-magnets::resources.sim_access'),
                __('lead-magnets::resources.sim_access_detail', ['product' => $product !== '' ? $product : __('lead-magnets::resources.map_connect_empty')])
                    .($takesOver ? ' '.__('lead-magnets::resources.sim_series_takes_over', ['series' => $series]) : ''),
                // The access she now holds, for the steps after this one (and for the account view of the dry run).
                $product !== '' ? ['slug' => $product] : [],
            );

            if (! $takesOver) {
                $line[] = Effect::mail($this->deliverySubject($resource), __('lead-magnets::resources.sim_delivery_detail'), 0, ['kind' => 'delivery']);
            }
        } else {
            $line[] = Effect::mail($this->deliverySubject($resource), __('lead-magnets::resources.sim_delivery_detail'), 0, ['kind' => 'delivery']);
        }

        return Effect::chain($line, [Effect::emit(
            new SimEvent(SimEvent::GRANTED, via: ['triggers', 'unlocks'], data: ['slug' => (string) $resource->handle]),
            __('lead-magnets::resources.sim_granted'),
        )]);
    }

    /**
     * One of the two mails of this lead magnet as it would read for the person: the map asks for the mail an
     * effect names (`meta.kind`: `confirmation` or `delivery`). Rendered by the delivery service like the real
     * mail and with nothing else: no grant, no signed link, no send.
     *
     * @param  array<string, mixed>  $meta
     * @return array{subject: string, html: string, from: ?string}|null
     */
    public function mailPreview(string $id, array $meta, SimPerson $person): ?array
    {
        $resource = $this->resolve($id);
        $kind = $meta['kind'] ?? null;

        if ($resource === null || ! in_array($kind, ['confirmation', 'delivery'], true)) {
            return null;
        }

        $email = $person->isKnown() ? ($person->trait('contact.email') ?? $person->trait('subscriber.email')) : null;

        return app(DeliveryService::class)->previewMail($resource, (string) $kind, is_string($email) && $email !== '' ? $email : 'max@example.com');
    }

    /** The standard delivery subject (the e-mail templates addon may replace it; not read here). */
    protected function deliverySubject(Resource $resource): string
    {
        return (string) __('lead-magnets::mail.delivery_subject'.($resource->isAccess() ? '_access' : ''), ['title' => $resource->title]);
    }
}
