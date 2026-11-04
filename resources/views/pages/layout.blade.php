<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? config('newsletter.branding.name') }}</title>
    <style>
        :root { --nl-bg: #f4f4f5; --nl-card: #fff; --nl-text: #18181b; --nl-muted: #52525b; --nl-accent: {{ config('newsletter.branding.accent_color', '#111827') }}; --nl-border: #e4e4e7; }
        @media (prefers-color-scheme: dark) { :root { --nl-bg: #18181b; --nl-card: #27272a; --nl-text: #f4f4f5; --nl-muted: #a1a1aa; --nl-border: #3f3f46; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--nl-bg); color: var(--nl-text); font: 16px/1.6 -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        main { max-width: 520px; margin: 8vh auto; padding: 0 16px; }
        .card { background: var(--nl-card); border: 1px solid var(--nl-border); border-radius: 10px; padding: 28px; }
        h1 { font-size: 1.35rem; margin: 0 0 .75rem; }
        p { margin: 0 0 1rem; color: var(--nl-muted); }
        button, .btn { display: inline-block; background: var(--nl-accent); color: #fff; border: 0; border-radius: 6px; padding: 10px 18px; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
        button.secondary { background: transparent; color: var(--nl-text); border: 1px solid var(--nl-border); }
        label { display: flex; gap: .6rem; align-items: flex-start; padding: .6rem 0; border-top: 1px solid var(--nl-border); }
        label small { display: block; color: var(--nl-muted); }
        .brand { text-align: center; margin-bottom: 1rem; font-weight: 700; }
        .brand a { color: var(--nl-text); text-decoration: none; }
        .status { padding: .6rem .8rem; border-radius: 6px; background: color-mix(in srgb, var(--nl-accent) 12%, transparent); color: var(--nl-text); }
    </style>
</head>
<body>
<main>
    <div class="brand"><a href="{{ config('newsletter.branding.url', url('/')) }}">{{ config('newsletter.branding.name', config('app.name')) }}</a></div>
    <div class="card">
        @yield('content')
    </div>
</main>
</body>
</html>
