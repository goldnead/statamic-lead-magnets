<?php

namespace Goldnead\LeadMagnets\Services;

use Carbon\CarbonImmutable;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\LeadMagnets\Contracts\AccessSubjectResolver;
use Goldnead\LeadMagnets\Events\DeliveryVariables;
use Goldnead\LeadMagnets\Events\ResourceDelivered;
use Goldnead\LeadMagnets\Integrations\AutomationsBridge;
use Goldnead\LeadMagnets\Integrations\EmailTemplatesBridge;
use Goldnead\LeadMagnets\Integrations\SuppressionBridge;
use Goldnead\LeadMagnets\Integrations\UnresolvedPlaceholders;
use Goldnead\LeadMagnets\Mail\ConfirmationMail;
use Goldnead\LeadMagnets\Mail\DeliveryMail;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
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

        try {
            $rendered = $this->templates->render(
                (string) $grant->resource?->mailTemplate('confirmation'),
                $this->resolveVariables($grant, 'confirmation') + [
                    'confirm_url' => $url,
                    // The disclosure for a resource that also subscribes a list,
                    // empty otherwise. The copy on the grant, which is what the
                    // consent record will carry.
                    'list_consent_text' => (string) ($grant->listConsent()['text'] ?? ''),
                ],
            );
        } catch (UnresolvedPlaceholders $e) {
            $this->holdForTemplate($grant, 'confirmation', $e);

            return false;
        }

        // The disclosure has to be in front of the reader. The confirmation
        // page shows it next to the button whatever the mail says, but a mail
        // that promises a download and is silent about the newsletter is the
        // opposite of the point. Named, so somebody adds the variable.
        if ($rendered !== null && ($consent = $grant->listConsent()) !== null
            && ! str_contains(
                html_entity_decode(strip_tags($rendered['html']), ENT_QUOTES | ENT_HTML5),
                $consent['text'],
            )) {
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

        if ($grant->resource?->isAccess()) {
            return $this->deliverAccess($grant);
        }

        // For a resource with a list of files the one `download_url` is the
        // overview page, and `groups` carries every file with its own signed
        // link, so the mail is complete without the page. A single file keeps
        // the one direct link and no list.
        $url = $this->links->for($grant);
        $groups = $grant->resource?->hasMultipleFiles() ? $this->links->groupedFor($grant) : null;
        /** @var view-string $fileGroupsView */
        $fileGroupsView = 'lead-magnets::partials.file-groups';

        try {
            $rendered = $this->templates->render(
                (string) $grant->resource?->mailTemplate('delivery'),
                $this->resolveVariables($grant, 'delivery') + [
                    'download_url' => $url,
                    // Rendered by Blade, which escapes labels and group names; the
                    // bridge inserts it raw (see `EmailTemplatesBridge::RAW_VARIABLES`).
                    'file_list' => $groups === null ? '' : app('view')->make($fileGroupsView, ['groups' => $groups])->render(),
                ],
            );
        } catch (UnresolvedPlaceholders $e) {
            $this->holdForTemplate($grant, 'delivery', $e);

            return false;
        }

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

    /**
     * Do not send a mail whose template keeps a `{{ … }}`.
     *
     * Logged as an error naming the resource, the template and the leftover,
     * and left on the grant as `last_hold` — the same place the other holds
     * (suppressed, sender refused) already go, so the CP shows it with no new
     * surface. Delivery templates support plain variables only.
     */
    protected function holdForTemplate(Grant $grant, string $kind, UnresolvedPlaceholders $e): void
    {
        Log::error('statamic-lead-magnets: the '.$kind.' mail for ['.$grant->resource?->handle.'] was not sent. The template ['.$e->slug.'] still contains ['.mb_substr($e->snippet, 0, 200).'] after the variables were filled in. Mail templates support plain {{ variable }} only, no {{ if }}.');

        $this->note($grant, $kind.'_template_unresolved');
    }

    /**
     * Open the product behind an `access` resource for the confirmed reader.
     *
     * The order matters. The series is asked first, because granting the
     * access is what starts it, and after that there is always a run. Then the
     * host resolves the address to an account, and the grant is written on that
     * account with entitlements (idempotent, so a second request or a resend
     * adds nothing). Last comes the mail: the sign-in link, unless a series
     * takes over for this first-time reader and its first mail replaces it.
     *
     * Every refusal leaves its reason on the grant instead of throwing, the
     * way the other holds do.
     */
    protected function deliverAccess(Grant $grant): bool
    {
        $resource = $grant->resource;

        if (trim((string) $resource->access_product) === '') {
            Log::error('statamic-lead-magnets: the access resource ['.$resource->handle.'] names no product, so nothing was granted.');
            $this->note($grant, 'access_product_missing');

            return false;
        }

        if (! app()->bound(AccessSubjectResolver::class)) {
            Log::error('statamic-lead-magnets: the access resource ['.$resource->handle.'] needs the host to bind '.AccessSubjectResolver::class.'. Nothing was granted.');
            $this->note($grant, 'access_resolver_missing');

            return false;
        }

        $source = (string) config('lead-magnets.entitlements.access_source', 'lead_magnet_access');
        // A reader who asks again after the access lapsed gets a new period, the
        // way the grant itself does (`attempt`): the lapsed row stays a true
        // record, and a second row is what entitlements expects of repeat access.
        $sourceRef = $grant->attempt > 1 ? $resource->handle.':'.$grant->attempt : (string) $resource->handle;

        try {
            $resolver = app(AccessSubjectResolver::class);
            $subject = $resolver->subjectFor($grant->email, $grant);

            // Whether this period's access row is new. Only a new row fires
            // `entitlements.granted`, which is what starts a series, so a series
            // can only take over when this call writes it. An existing row means
            // the reader has been through here before: the delivery mail is
            // the safe answer, and a series that "takes over" without a trigger
            // would leave them with no mail at all.
            $isNew = ! Entitlements::forSubject($subject)
                ->where('product_slug', (string) $resource->access_product)
                ->where('source', $source)
                ->where('source_ref', $sourceRef)
                ->exists();

            $seriesTakesOver = $isNew
                && ($series = $resource->seriesHandle()) !== null
                && app(AutomationsBridge::class)->takesOver($series, $grant->email);

            $access = Entitlements::grant(
                $subject,
                (string) $resource->access_product,
                $source,
                $sourceRef,
                null,
                ($days = $resource->grantTtlDays()) === null ? null : CarbonImmutable::now('UTC')->addDays($days),
                null,
                ['lead_magnet_grant_id' => $grant->id, 'lead_magnet_resource' => $resource->handle],
            );

            // A revoked access stays revoked: somebody withdrew it on purpose,
            // and a form is not the place to overturn that. A mail with a sign-in
            // link would promise what the account does not have.
            if (! $access->state()->grantsAccess()) {
                Log::warning('statamic-lead-magnets: the access to ['.$resource->access_product.'] for ['.$resource->handle.'] does not stand ('.$access->state()->value.'); no mail was sent.');
                $this->note($grant, 'access_not_open');

                return false;
            }

            $accessUrl = $seriesTakesOver ? null : $resolver->accessUrl($subject, $resource, $grant);
        } catch (\Throwable $e) {
            Log::error('statamic-lead-magnets: access for ['.$resource->handle.'] could not be granted: '.$e->getMessage());
            $this->note($grant, 'access_grant_failed');

            return false;
        }

        if ($seriesTakesOver) {
            $this->markDelivered($grant, 'series');

            return true;
        }

        $url = $accessUrl ?? url($resource->access_path ?: '/');

        try {
            $rendered = $this->templates->render(
                (string) $resource->mailTemplate('delivery'),
                $this->resolveVariables($grant, 'delivery') + ['access_url' => $url, 'download_url' => $url],
            );
        } catch (UnresolvedPlaceholders $e) {
            $this->holdForTemplate($grant, 'delivery', $e);

            return false;
        }

        $sent = app(BrandMailer::class)->send(null, $grant->email, null, new DeliveryMail(
            $grant,
            $url,
            $rendered['html'] ?? null,
            $rendered['subject'] ?? null,
        ));

        if (! $sent) {
            $this->note($grant, 'delivery_sender_refused');

            return false;
        }

        $this->markDelivered($grant, 'mail');

        return true;
    }

    /**
     * One of the two mails of a resource as it would read for a person, for the dry run of the map. Rendered the
     * way the real mail is (the template the editor wrote, else the addon's own view) and nothing else: no grant
     * row, no signed link (the address in it is `#`), no suppression check, no mail, no event. A template that
     * names a placeholder this mail does not carry, or that is gone, has no preview: null.
     *
     * @param  string  $kind  `confirmation` or `delivery`
     * @return array{subject: string, html: string, from: ?string}|null
     */
    public function previewMail(Resource $resource, string $kind, string $email): ?array
    {
        if (! in_array($kind, ['confirmation', 'delivery'], true)) {
            return null;
        }

        // A grant that is only held in memory: the views read the resource and the address from it.
        $grant = (new Grant)->forceFill(['email' => $email]);
        $grant->setRelation('resource', $resource);

        $variables = $this->variables($grant) + ($kind === 'confirmation'
            ? ['confirm_url' => '#', 'list_consent_text' => '']
            : ['download_url' => '#', 'access_url' => '#', 'file_list' => '']);

        try {
            $rendered = $this->templates->render((string) $resource->mailTemplate($kind), $variables);
        } catch (UnresolvedPlaceholders) {
            return null;
        }

        if ($rendered !== null) {
            return [
                'subject' => (string) ($rendered['subject'] ?? __('lead-magnets::mail.'.$kind.'_subject'.($kind === 'delivery' && $resource->isAccess() ? '_access' : ''), ['title' => $resource->title])),
                'html' => $rendered['html'],
                'from' => null,
            ];
        }

        // No template, or none that resolves: the mail the addon sends on its own.
        $mailable = $kind === 'confirmation' ? new ConfirmationMail($grant, '#') : new DeliveryMail($grant, '#');
        $built = $mailable->build();

        return [
            'subject' => (string) $built->subject,
            'html' => $built->render(),
            'from' => null,
        ];
    }

    /** Stamp the delivery on the grant and announce it. `$how` is `mail` or `series`. */
    protected function markDelivered(Grant $grant, string $how): void
    {
        $grant->forceFill([
            'delivered_at' => Carbon::now(),
            'meta' => array_merge($grant->meta ?? [], ['delivery' => $how]),
        ])->save();

        ResourceDelivered::dispatch($grant);
    }

    /**
     * The variables for a mail, after listeners have had their say.
     *
     * The seam for a host that needs one more placeholder (a link of its own,
     * say) without subclassing this service. {@see DeliveryVariables}.
     *
     * @return array<string, string>
     */
    protected function resolveVariables(Grant $grant, string $kind): array
    {
        $event = new DeliveryVariables($grant, $kind, $this->variables($grant));

        event($event);

        return $event->variables;
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
