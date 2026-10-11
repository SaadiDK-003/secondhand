<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>@yield('title') — Second Hand</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @vite('resources/css/app.css')
    </head>
    <body class="min-h-screen bg-background font-sans text-foreground antialiased">
        <header class="border-b bg-card px-5 py-5">
            <a href="/" class="mx-auto flex max-w-6xl items-center gap-2 text-xl font-bold" aria-label="Second Hand home">
                <img src="/favicon.svg" alt="" width="36" height="36">
                <span><span class="text-primary">2nd</span> Hand<span class="text-primary">.</span></span>
            </a>
        </header>
        <main class="mx-auto max-w-xl px-5 py-12 sm:py-24">
            <section class="rounded-2xl border bg-card p-6 sm:p-10">
                <p class="text-sm font-semibold text-primary">@yield('code')</p>
                <h1 class="mt-3 text-3xl font-bold tracking-tight">@yield('title')</h1>
                <p class="mt-4 leading-relaxed text-muted-foreground">@yield('message')</p>
                <a href="/search" class="mt-7 inline-flex min-h-11 items-center rounded-xl bg-primary px-5 py-3 font-semibold text-white">Explore available items</a>
                <a href="/" class="mt-5 block w-fit py-2 text-sm text-primary underline">Back to home</a>
            </section>
        </main>
    </body>
</html>
