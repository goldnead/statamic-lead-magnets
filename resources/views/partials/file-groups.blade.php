{{--
    Every file of a multi-file resource, grouped. One partial for the delivery
    mail and the download page so the two cannot disagree about order or
    grouping; both receive `$groups` from `DownloadLink::groupedFor()`.

    Blade escapes the labels and group names, which an editor typed. The
    markup is plain on purpose: mail clients strip most styling, and the page
    brings its own.
--}}
@foreach ($groups as $group)
    @if ($group['name'] !== null)
        <h3 style="margin: 1.25rem 0 .25rem; font-size: 1rem;">{{ $group['name'] }}</h3>
    @endif
    <ul style="margin: 0 0 .5rem; padding-left: 1.25rem;">
        @foreach ($group['files'] as $file)
            <li><a href="{{ $file['url'] }}">{{ $file['label'] }}</a></li>
        @endforeach
    </ul>
@endforeach
