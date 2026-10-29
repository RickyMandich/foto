@props([
    'title' => null,
    'description' => null,
    'image' => null,
    'ogUrl' => null,
    'ogType' => 'website',
    'canonical' => null,
    'app' => false,   // true: pagina "a tutto schermo" senza header e footer (pagina galleria)
])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ? $title.' — ' : '' }}{{ config('app.name') }}</title>
    @if ($description)
        <meta name="description" content="{{ $description }}">
    @endif

    <link rel="canonical" href="{{ $canonical ?? url()->current() }}">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:title" content="{{ $title ?? config('app.name') }}">
    <meta property="og:description" content="{{ $description ?? '' }}">
    <meta property="og:url" content="{{ $ogUrl ?? url()->current() }}">
    <meta property="og:locale" content="it_IT">
    @isset($image)<meta property="og:image" content="{{ $image }}">@endisset
    <meta name="twitter:card" content="{{ isset($image) ? 'summary_large_image' : 'summary' }}">

    @if (! config('photos.indexable', false))
        <meta name="robots" content="noindex, nofollow">
    @endif

    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <meta name="theme-color" content="#0a0a0a">

    @isset($head){{ $head }}@endisset
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full {{ $app ? 'h-dvh overflow-hidden flex flex-col' : '' }}">
    <a href="#contenuto" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-neutral-900 focus:p-3">Vai al contenuto</a>

    @unless ($app)
        <header class="border-b border-neutral-800 bg-neutral-900/60">
            <nav class="mx-auto flex max-w-7xl items-center gap-6 px-4 py-3" aria-label="Principale">
                <a href="{{ route('home') }}" class="text-lg font-semibold text-neutral-100">{{ config('app.name') }}</a>
                <a href="{{ route('home') }}" class="text-sm">Eventi</a>
                <a href="{{ route('authors.index') }}" class="text-sm">Autori</a>
            </nav>
        </header>
    @endunless

    <main id="contenuto" class="{{ $app ? 'min-h-0 flex-1' : 'mx-auto max-w-7xl px-4 py-8' }}">
        {{ $slot }}
    </main>

    @unless ($app)
        <footer class="mx-auto max-w-7xl px-4 py-8 text-sm text-neutral-500">
            © {{ now()->year }} {{ config('app.name') }}
        </footer>
    @endunless
</body>
</html>
