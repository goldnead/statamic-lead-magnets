{{--
    A deliberately plain shell for the two public pages this addon serves.

    Not the site's own layout: these pages are opened from a mail client, often
    long after the visit that produced them, and inheriting a theme layout
    would drag the site's navigation, cookie banner and analytics into a page
    whose only job is to say yes or no. Publish the views
    (`php artisan vendor:publish --tag=lead-magnets-views`) to replace it.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', __('lead-magnets::public.title'))</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            margin: 0; padding: 3rem 1.25rem; line-height: 1.6;
            background: Canvas; color: CanvasText;
        }
        main { max-width: 34rem; margin: 0 auto; }
        h1 { font-size: 1.5rem; margin: 0 0 .75rem; }
        p { margin: 0 0 1rem; }
        .muted { opacity: .7; font-size: .9rem; }
    </style>
</head>
<body>
    <main>
        @yield('content')
    </main>
</body>
</html>
