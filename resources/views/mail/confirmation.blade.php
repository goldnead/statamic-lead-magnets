<p>{{ __('lead-magnets::mail.confirmation_greeting') }}</p>

<p>{{ __('lead-magnets::mail.confirmation_body', ['title' => $resource?->title ?? '']) }}</p>

<p><a href="{{ $confirmUrl }}">{{ __('lead-magnets::mail.confirmation_cta') }}</a></p>

<p>{{ __('lead-magnets::mail.confirmation_ignore') }}</p>
