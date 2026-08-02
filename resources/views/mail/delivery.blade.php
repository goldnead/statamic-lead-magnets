<p>{{ __('lead-magnets::mail.delivery_greeting') }}</p>

<p>{{ __('lead-magnets::mail.delivery_body', ['title' => $resource?->title ?? '']) }}</p>

<p><a href="{{ $downloadUrl }}">{{ __('lead-magnets::mail.delivery_cta') }}</a></p>

<p>{{ __('lead-magnets::mail.delivery_expiry') }}</p>
