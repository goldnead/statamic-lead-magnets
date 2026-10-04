<?php

namespace Goldnead\LeadMagnets\Services;

use Goldnead\LeadMagnets\Events\ResourceDelivered;
use Goldnead\LeadMagnets\Integrations\EmailTemplatesBridge;
use Goldnead\LeadMagnets\Integrations\SuppressionBridge;
use Goldnead\LeadMagnets\Mail\ConfirmationMail;
use Goldnead\LeadMagnets\Mail\DeliveryMail;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Sending\BrandMailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

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
            (string) $grant->resource?->mailTemplate('confirmation'),
            $this->variables($grant) + [
                'confirm_url' => $url,
                // The disclosure for a resource that also subscribes a list,
                // empty otherwise. The copy on the grant, which is what the
                // consent record will carry.
                'list_consent_text' => (string) ($grant->listConsent()['text'] ?? ''),
            ],
        );

        // The disclosure has to be in front of the reader. The confirmation
        // page shows it next to the button whatever the mail says, but a mail
        // that promises a download and is silent about the newsletter is the
        // opposite of the point. Named, so somebody adds the variable.
        if ($rendered !== null && ($consent = $grant->listConsent()) !== null
            && ! str_contains($rendered['html'], e($consent['text']))) {
            Log::warning('statamic-lead-magnets: the confirmation template ['.$grant->resource?->mailTemplate('confirmation').'] for ['.$grant->resource?->handle.'] does not show the newsletter disclosure. Add {{ list_consent_text }} to it.');
        }

        // Through the brand mailer, not Mail::to(): both mails here go to a
        // member of the public who just handed over an address, and one that
        // arrives under another brand's name asks them to trust a sender they
        // never heard of. On a multi-brand host the process-wide default is
        // whichever brand booted first.
        $sent = app(BrandMailer::class)->send(null, $grant->email, null, new ConfirmationMail(
            $grant,
            $url,
            $rendered['html'] ?? null,
            $rendered['subject'] ?? null,
        ));

        if (! $sent) {
            // The refusal and its reason are already in the log. Recording it on
            // the grant is what makes it answerable later: "they never got the
            // mail" has a cause attached instead of being a mystery.
            $this->note($grant, 'confirmation_sender_refused');

            return false;
        }

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

        // For a resource with a list of files the one `download_url` is the
        // overview page, and `groups` carries every file with its own signed
        // link, so the mail is complete without the page. A single file keeps
        // the one direct link and no list.
        $url = $this->links->for($grant);
        $groups = $grant->resource?->hasMultipleFiles() ? $this->links->groupedFor($grant) : null;
        /** @var view-string $fileGroupsView */
        $fileGroupsView = 'lead-magnets::partials.file-groups';

        $rendered = $this->templates->render(
            (string) $grant->resource?->mailTemplate('delivery'),
            $this->variables($grant) + [
                'download_url' => $url,
                // Rendered by Blade, which escapes labels and group names; the
                // bridge inserts it raw (see `EmailTemplatesBridge::RAW_VARIABLES`).
                'file_list' => $groups === null ? '' : app('view')->make($fileGroupsView, ['groups' => $groups])->render(),
            ],
        );

        if ($groups !== null) {
            $this->warnWhenTemplateIgnoresList($grant, $rendered, $groups);
        }

        $sent = app(BrandMailer::class)->send(null, $grant->email, null, new DeliveryMail(
            $grant,
            $url,
            $rendered['html'] ?? null,
            $rendered['subject'] ?? null,
            $groups,
        ));

        if (! $sent) {
            $this->note($grant, 'delivery_sender_refused');

            return false;
        }

        $grant->forceFill(['delivered_at' => Carbon::now()])->save();

        ResourceDelivered::dispatch($grant);

        return true;
    }

    /**
     * Say so in the log when the mail goes out without the list.
     *
     * A delivery view published into the host before files had a list, or an
     * email-templates template written for one link, has no place for nine
     * files: the mail would say "Download" with one link to an overview page —
     * which still works, but is not what the editor built the list for, and
     * nothing on screen says why. The warning names the fix.
     *
     * @param  array{html: string, subject: string|null}|null  $rendered
     * @param  list<array{name: string|null, files: list<array{key: string, label: string, url: string}>}>  $groups
     */
    protected function warnWhenTemplateIgnoresList(Grant $grant, ?array $rendered, array $groups): void
    {
        $firstKey = $groups[0]['files'][0]['key'] ?? null;

        if ($firstKey === null) {
            return;
        }

        $handle = (string) $grant->resource?->handle;

        if ($rendered !== null) {
            if (! str_contains($rendered['html'], $firstKey)) {
                Log::warning('statamic-lead-magnets: the delivery mail template ['.$grant->resource?->mailTemplate('delivery').'] does not list the files of ['.$handle.']. Add {{ file_list }} to it.');
            }

            return;
        }

        $path = (string) app('view')->getFinder()->find('lead-magnets::mail.delivery');
        $shipped = (string) realpath(__DIR__.'/../../resources/views');

        if (! str_starts_with((string) realpath($path), $shipped) && ! str_contains((string) file_get_contents($path), 'groups')) {
            Log::warning('statamic-lead-magnets: the published delivery view ['.$path.'] does not list the files of ['.$handle.']. Republish it: php artisan vendor:publish --tag=lead-magnets-views --force');
        }
    }

    /** @return array<string, string> */
    protected function variables(Grant $grant): array
    {
        $resource = $grant->resource;

        return [
            'email' => $grant->email,
            'resource_title' => (string) $resource->title,
            'resource_handle' => (string) $resource->handle,
            'resource_description' => (string) ($resource->description ?? ''),
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
