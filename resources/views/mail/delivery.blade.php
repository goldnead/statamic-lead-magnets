<p>{{ __('lead-magnets::mail.delivery_greeting') }}</p>

<p>{{ __('lead-magnets::mail.delivery_body', ['title' => $resource?->title ?? '']) }}</p>

@if (! empty($groups))
    @include('lead-magnets::partials.file-groups', ['groups' => $groups])

    <p><a href="{{ $downloadUrl }}">{{ __('lead-magnets::mail.delivery_all_on_one_page') }}</a></p>
@else
    <p><a href="{{ $downloadUrl }}">{{ __('lead-magnets::mail.delivery_cta') }}</a></p>
@endif

<p>{{ __('lead-magnets::mail.delivery_expiry') }}</p>
