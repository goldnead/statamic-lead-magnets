{{--
    Plain text: `{!! !!}`, not `{{ }}`. Escaping is for HTML, and in a text mail it
    turns the `&` of a signed URL into `&amp;`, which changes the signature's
    query string and makes the link answer 403.
--}}
{!! __('lead-magnets::mail.delivery_greeting') !!}

{!! __('lead-magnets::mail.delivery_body', ['title' => $resource?->title ?? '']) !!}

@if (! empty($groups))
@foreach ($groups as $group)
@if ($group['name'] !== null)
{!! $group['name'] !!}
@endif
@foreach ($group['files'] as $file)
- {!! $file['label'] !!}: {!! $file['url'] !!}
@endforeach

@endforeach
{!! __('lead-magnets::mail.delivery_all_on_one_page') !!}: {!! $downloadUrl !!}
@else
{!! $downloadUrl !!}
@endif

{!! __('lead-magnets::mail.delivery_expiry') !!}
