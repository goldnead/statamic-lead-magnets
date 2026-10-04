{{--
    Every file of a multi-file resource, grouped. One partial for the delivery
    mail and the download page so the two cannot disagree about order or
    grouping; both receive `$groups` from `DownloadLink::groupedFor()`.

    Blade escapes the labels and group names, which an editor typed. The
    markup is plain on purpose: mail clients strip most styling, and the page
    brings its own.

    Every file is its own row with a link that fills it: 12px above and below a
    20px line is 44px of tap target (WCAG 2.5.8 asks for 24px, a thumb for more),
    and a hairline between rows keeps nine links from reading as one paragraph.
    Inline styles, because a mail client drops a stylesheet.
--}}
@foreach ($groups as $group)
    @if ($group['name'] !== null)
        <h3 style="margin: 1.5rem 0 .25rem; font-size: 1rem;">{{ $group['name'] }}</h3>
    @endif
    <ul style="list-style: none; margin: 0 0 .5rem; padding: 0; border-bottom: 1px solid #d4d4d8;">
        @foreach ($group['files'] as $file)
            <li style="margin: 0; padding: 0; border-top: 1px solid #d4d4d8;">
                <a href="{{ $file['url'] }}" style="display: block; padding: 12px 4px; line-height: 20px; min-height: 20px;">{{ $file['label'] }}</a>
            </li>
        @endforeach
    </ul>
@endforeach
