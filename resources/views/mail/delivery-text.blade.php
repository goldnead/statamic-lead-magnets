{{ __('lead-magnets::mail.delivery_greeting') }}

{{ __('lead-magnets::mail.delivery_body', ['title' => $resource?->title ?? '']) }}

{{ $downloadUrl }}

{{ __('lead-magnets::mail.delivery_expiry') }}
