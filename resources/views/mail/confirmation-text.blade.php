{{-- Plain text: unescaped on purpose, see delivery-text.blade.php. --}}
{!! __('lead-magnets::mail.confirmation_greeting') !!}

{!! __('lead-magnets::mail.confirmation_body'.($resource?->isAccess() ? '_access' : ''), ['title' => $resource?->title ?? '']) !!}

@if ($consent = $grant->listConsent())
{!! $consent['text'] !!}

@endif
{!! $confirmUrl !!}

{!! __('lead-magnets::mail.confirmation_ignore') !!}
