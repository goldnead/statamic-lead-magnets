<p>{{ __('lead-magnets::mail.confirmation_greeting') }}</p>

<p>{{ __('lead-magnets::mail.confirmation_body', ['title' => $resource?->title ?? '']) }}</p>

@if ($consent = $grant->listConsent())
    {{-- The disclosure for a resource that also subscribes a mailing list.
         The same sentence the consent record will carry. --}}
    <p>{{ $consent['text'] }}</p>
@endif

<p><a href="{{ $confirmUrl }}">{{ __('lead-magnets::mail.confirmation_cta') }}</a></p>

<p>{{ __('lead-magnets::mail.confirmation_ignore') }}</p>
