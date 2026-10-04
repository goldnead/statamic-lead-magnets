<p>{{ __('lead-magnets::mail.delivery_greeting') }}</p>

<p>{{ __('lead-magnets::mail.delivery_body', ['title' => $resource?->title ?? '']) }}</p>

@if (! empty($groups))
    @include('lead-magnets::partials.file-groups', ['groups' => $groups])

    <p style="margin: 1rem 0;"><a href="{{ $downloadUrl }}" style="display: inline-block; padding: 12px 0; line-height: 20px;">{{ __('lead-magnets::mail.delivery_all_on_one_page') }}</a></p>
@else
    <p><a href="{{ $downloadUrl }}">{{ __('lead-magnets::mail.delivery_cta') }}</a></p>
@endif

<p>{{ __('lead-magnets::mail.delivery_expiry') }}</p>
