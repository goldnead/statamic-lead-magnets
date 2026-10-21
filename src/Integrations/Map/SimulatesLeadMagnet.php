<?php

namespace Goldnead\LeadMagnets\Integrations\Map;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\LeadMagnets\Models\Resource;

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
            return [Effect::mail($this->deliverySubject($resource), __('lead-magnets::resources.sim_delivery_again_detail'))];
        }

        $line = [];

        if ($resource->requires_confirmation) {
            $line[] = Effect::mail(
                __('lead-magnets::mail.confirmation_subject', ['title' => $resource->title]),
                __('lead-magnets::resources.sim_confirmation_detail'),
            );
            $line[] = new Effect(
                Effect::WAIT,
                __('lead-magnets::resources.sim_wait_confirm'),
                __('lead-magnets::resources.sim_wait_confirm_detail'),
                0,
                ['duration_known' => false],
            );
        }

        $line[] = Effect::mail($this->deliverySubject($resource), __('lead-magnets::resources.sim_delivery_detail'));

        if ($resource->isAccess()) {
            $product = trim((string) $resource->access_product);

            $line[] = Effect::access(
                __('lead-magnets::resources.sim_access'),
                __('lead-magnets::resources.sim_access_detail', ['product' => $product !== '' ? $product : __('lead-magnets::resources.map_connect_empty')]),
            );
        }

        return Effect::chain($line, [Effect::emit(
            new SimEvent(SimEvent::GRANTED, via: ['triggers', 'unlocks'], data: ['slug' => (string) $resource->handle]),
            __('lead-magnets::resources.sim_granted'),
        )]);
    }

    /** The standard delivery subject (the e-mail templates addon may replace it; not read here). */
    protected function deliverySubject(Resource $resource): string
    {
        return (string) __('lead-magnets::mail.delivery_subject'.($resource->isAccess() ? '_access' : ''), ['title' => $resource->title]);
    }
}
