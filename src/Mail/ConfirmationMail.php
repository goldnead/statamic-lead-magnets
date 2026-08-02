<?php

namespace Goldnead\LeadMagnets\Mail;

use Goldnead\LeadMagnets\Models\Grant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * "Was this you? Confirm and the file is on its way."
 *
 * Ships as a Blade view so the addon can send it with no sibling installed.
 * When goldnead/statamic-email-templates is present the body comes from the
 * template an editor authored there instead; see MailComposer.
 */
class ConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Grant $grant,
        public string $confirmUrl,
        public ?string $renderedHtml = null,
        public ?string $renderedSubject = null,
    ) {}

    public function build(): self
    {
        $resource = $this->grant->resource;

        $subject = $this->renderedSubject
            ?: __('lead-magnets::mail.confirmation_subject', ['title' => $resource->title]);

        $mail = $this->subject($subject);

        if ($this->renderedHtml !== null) {
            return $mail->html($this->renderedHtml);
        }

        return $mail->view('lead-magnets::mail.confirmation', [
            'grant' => $this->grant,
            'resource' => $resource,
            'confirmUrl' => $this->confirmUrl,
        ])->text('lead-magnets::mail.confirmation-text', [
            'grant' => $this->grant,
            'resource' => $resource,
            'confirmUrl' => $this->confirmUrl,
        ]);
    }
}
