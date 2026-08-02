<?php

namespace Goldnead\LeadMagnets\Mail;

use Goldnead\LeadMagnets\Models\Grant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * The mail that carries the signed download link.
 *
 * The link is not an attachment and the file is not attached: a signed link is
 * revocable, countable and audited, and an attachment is none of those.
 */
class DeliveryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Grant $grant,
        public string $downloadUrl,
        public ?string $renderedHtml = null,
        public ?string $renderedSubject = null,
    ) {}

    public function build(): self
    {
        $resource = $this->grant->resource;

        $subject = $this->renderedSubject
            ?: __('lead-magnets::mail.delivery_subject', ['title' => $resource?->title ?? '']);

        $mail = $this->subject($subject);

        if ($this->renderedHtml !== null) {
            return $mail->html($this->renderedHtml);
        }

        return $mail->view('lead-magnets::mail.delivery', [
            'grant' => $this->grant,
            'resource' => $resource,
            'downloadUrl' => $this->downloadUrl,
        ])->text('lead-magnets::mail.delivery-text', [
            'grant' => $this->grant,
            'resource' => $resource,
            'downloadUrl' => $this->downloadUrl,
        ]);
    }
}
