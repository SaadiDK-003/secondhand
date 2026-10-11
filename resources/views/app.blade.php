<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'light') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "light" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">


        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            @php($seo = $page['props']['seo'] ?? null)
            <title>{{ $seo['title'] ?? config('app.name', 'Second Hand') }} - Second Hand</title>
            @if($seo)
                <meta name="description" content="{{ $seo['description'] }}" data-inertia="description">
                <meta name="robots" content="{{ $seo['robots'] }}" data-inertia="robots">
                <link rel="canonical" href="{{ $seo['canonical'] }}" data-inertia="canonical">
                <meta property="og:title" content="{{ $seo['title'] }}" data-inertia="og:title">
                <meta property="og:description" content="{{ $seo['description'] }}" data-inertia="og:description">
                <meta property="og:url" content="{{ $seo['canonical'] }}" data-inertia="og:url">
                <meta property="og:type" content="website" data-inertia="og:type">
                <meta name="twitter:card" content="{{ $seo['image'] ? 'summary_large_image' : 'summary' }}" data-inertia="twitter:card">
                @if($seo['image'])<meta property="og:image" content="{{ $seo['image'] }}" data-inertia="og:image">@endif
                @if($seo['schema'] !== '[]')<script type="application/ld+json" data-inertia="schema">{!! $seo['schema'] !!}</script>@endif
            @endif
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
