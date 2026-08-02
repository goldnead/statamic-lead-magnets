{{ __('lead-magnets::mail.confirmation_greeting') }}

{{ __('lead-magnets::mail.confirmation_body', ['title' => $resource?->title ?? '']) }}

{{ $confirmUrl }}

{{ __('lead-magnets::mail.confirmation_ignore') }}
