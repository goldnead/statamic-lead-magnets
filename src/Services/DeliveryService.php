<?php

namespace Goldnead\LeadMagnets\Services;

use Goldnead\LeadMagnets\Events\ResourceDelivered;
use Goldnead\LeadMagnets\Integrations\EmailTemplatesBridge;
use Goldnead\LeadMagnets\Integrations\SuppressionBridge;
use Goldnead\LeadMagnets\Mail\ConfirmationMail;
use Goldnead\LeadMagnets\Mail\DeliveryMail;
use Goldnead\LeadMagnets\Models\Grant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Everything that leaves the building by mail.
 *
 * Two sends, one gate. The suppression check sits in front of both: an address
 * that bounced hard or filed a complaint gets no confirmation request and no
 * delivery, and the grant records why nothing arrived instead of pretending
 * something did.
 */
class DeliveryService
{
    public function __construct(
        protected DownloadLink $links,
        protected SuppressionBridge $suppression,
        protected EmailTemplatesBridge $templates,
    ) {}

    /**
     * Send the confirmation request.
     *
     * Needs the plaintext token, which only exists on the object the request
     * just produced. A grant loaded from the database cannot be re-sent a
     * confirmation for the same token — the hash is one way — so the caller
     * asks for a fresh request instead. That is the point: a confirmation
     * link is a secret with one holder.
     */
    public function sendConfirmation(Grant $grant): bool
    {
        if ($grant->plainToken === null) {
            return false;
        }

        if ($this->suppression->blocks($grant->email)) {
            $this->note($grant, 'confirmation_suppressed');

            return false;
        }

        $url = route('lead-magnets.confirm', ['token' => $grant->plainToken]);

        $rendered = $this->templates->render(
            (string) config('lead-magnets.mail.confirmation_template', ''),
            $this->variables($grant) + ['confirm_url' => $url],
        );

        Mail::to($grant->email)->send(new ConfirmationMail(
            $grant,
            $url,
            $rendered['html'] ?? null,
            $rendered['subject'] ?? null,
        ));

        return true;
    }

    /**
     * Send the delivery mail with a fresh signed link.
     *
     * Re-sendable on purpose. A link that expired unused is a support request
     * ("the download doesn't work"), and the answer is a new link for the same
     * grant — not a new confirmation, because the address is already proven.
     */
    public function deliver(Grant $grant): bool
    {
        if (! $grant->isRedeemable()) {
            return false;
        }

        if ($this->suppression->blocks($grant->email)) {
            $this->note($grant, 'delivery_suppressed');

            return false;
        }

        $url = $this->links->for($grant);

        $rendered = $this->templates->render(
            (string) config('lead-magnets.mail.delivery_template', ''),
            $this->variables($grant) + ['download_url' => $url],
        );

        Mail::to($grant->email)->send(new DeliveryMail(
            $grant,
            $url,
            $rendered['html'] ?? null,
            $rendered['subject'] ?? null,
        ));

        $grant->forceFill(['delivered_at' => Carbon::now()])->save();

        ResourceDelivered::dispatch($grant);

        return true;
    }

    /** @return array<string, string> */
    protected function variables(Grant $grant): array
    {
        return [
            'email' => $grant->email,
            'resource_title' => (string) ($grant->resource?->title ?? ''),
            'resource_handle' => (string) ($grant->resource?->handle ?? ''),
            'resource_description' => (string) ($grant->resource?->description ?? ''),
        ];
    }

    /**
     * Leave the reason on the grant.
     *
     * A grant that says `active` and never delivered is a mystery; one that
     * says `delivery_suppressed` answers the support mail by itself.
     */
    protected function note(Grant $grant, string $reason): void
    {
        $grant->forceFill([
            'meta' => array_merge($grant->meta ?? [], [
                'last_hold' => $reason,
                'last_hold_at' => Carbon::now()->toIso8601String(),
            ]),
        ])->save();
    }
}
