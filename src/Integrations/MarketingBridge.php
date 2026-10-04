<?php

namespace Goldnead\LeadMagnets\Integrations;

use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Support\ListConsentPress;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Log;

/**
 * Optional: goldnead/statamic-marketing.
 *
 * When a resource names a mailing list and the address has confirmed, the
 * address is subscribed to it.
 *
 * By default this bridge does **not** borrow marketing's double-opt-in for the
 * resource itself: the confirmation this addon sends is consent to receive one
 * file, and a mailing-list subscription is a separate permission that a
 * resource request may not silently grant. The subscription then runs
 * marketing's own consent path — including marketing's own confirmation, if
 * that list asks for one.
 *
 * ## Coupled: one confirmation for both
 *
 * A resource can say that the file comes with the list
 * (`Resource::couplesListToConfirmation()`). Then the form and the confirmation
 * mail carry a disclosure sentence, the grant keeps a copy of it, and the
 * reader confirms on a button rather than by opening the link. Only when all of
 * that happened — the copy is on the grant, the resource still couples, and the
 * activation running right now was caused by the button press
 * (`ListConsentPress`) — is marketing told to skip its own confirmation (`skip_confirmation`, the
 * option marketing has for consent established elsewhere), and the
 * subscription's meta carries the consent: method, list, wording, source,
 * when it was asked for and when it was confirmed.
 *
 * Anything short of that falls back to the default above. An editor
 * reinstating a pending grant activates the file, but nobody pressed the
 * button, so marketing asks the reader itself.
 *
 * marketing itself is not modified by this package. The coupling is one
 * direction, through marketing's public service, and this addon's whole flow
 * works with marketing absent.
 */
class MarketingBridge extends Bridge
{
    /** @return class-string */
    protected function service(): string
    {
        return SubscriptionService::class;
    }

    /** @return class-string */
    protected function repository(): string
    {
        return MailingListRepository::class;
    }

    public function available(): bool
    {
        return $this->enabled('marketing')
            && class_exists($this->service())
            && interface_exists($this->repository());
    }

    public function onActivated(Grant $grant): void
    {
        $consent = $this->confirmedConsent($grant);

        // The list the reader was shown wins over what the resource says now.
        $handle = $consent['list'] ?? $grant->resource?->marketing_list;

        if (! $handle) {
            return;
        }

        if (! $this->available()) {
            // The reader was told they would be subscribed and pressed the
            // button for it. Saying nothing here would be the quiet kind of
            // failure that nobody notices until the list is short.
            if ($consent !== null) {
                Log::warning('[lead-magnets] '.$grant->email.' confirmed the list ['.$handle.'] through ['.$grant->resource?->handle.'], but the marketing addon is not available. Not subscribed.');
            }

            return;
        }

        $this->attempt('marketing subscription ['.$handle.']', function () use ($grant, $handle, $consent) {
            $lists = app($this->repository());
            $list = $lists->find($handle);

            if (! $list) {
                if ($consent !== null) {
                    Log::warning('[lead-magnets] The list ['.$handle.'] named on ['.$grant->resource?->handle.'] does not exist. A confirmed subscription was not recorded.');
                }

                return null;
            }

            $service = app($this->service());

            if (! method_exists($service, 'subscribe')) {
                return null;
            }

            $options = ['source' => 'lead-magnets', 'resource' => $grant->resource?->handle];

            if ($consent !== null) {
                $options['skip_confirmation'] = true;
                $options['meta'] = [
                    'consent' => [
                        'method' => 'lead_magnet_confirmation',
                        'list' => $consent['list'],
                        'text' => $consent['text'],
                        'source' => $consent['source'],
                        'requested_at' => $consent['requested_at'],
                        'confirmed_at' => $consent['confirmed_at'],
                    ],
                ];
            }

            return $service->subscribe($list, $grant->email, [], $options);
        });
    }

    /**
     * The disclosure on the grant, if and only if the reader confirmed it with
     * the button.
     *
     * @return array{list: string, text: string, source: string, requested_at: string, confirmed_at: string}|null
     */
    protected function confirmedConsent(Grant $grant): ?array
    {
        $pressedAt = app(ListConsentPress::class)->heldFor((int) $grant->id);
        $consent = $grant->listConsent();

        if ($pressedAt === null || $consent === null || ! $grant->couplesList()) {
            return null;
        }

        return [
            'list' => $consent['list'],
            'text' => $consent['text'],
            'source' => $consent['source'],
            'requested_at' => $consent['requested_at'],
            'confirmed_at' => $pressedAt,
        ];
    }
}
