<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @php
        $pageTitle = $title ?? config('app.name');
        // Only what the page actually said about itself. Nothing here invents
        // a description, and no private or customer-specific detail is ever
        // passed in -- these tags are public by definition.
        $pageDescription = $description
            ?? 'Buy products outright in cedis, or bid with credits and compete for them. A marketplace built for Ghana.';
    @endphp

    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ Str::limit(strip_tags($pageDescription), 160) }}">

    {{-- The page's own address, so a listing reached through filters or
         pagination does not read as a separate page to a search engine. --}}
    <link rel="canonical" href="{{ url()->current() }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ Str::limit(strip_tags($pageDescription), 160) }}">
    <meta property="og:url" content="{{ url()->current() }}">
    @isset($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
    @endisset
    <meta name="twitter:card" content="{{ isset($ogImage) ? 'summary_large_image' : 'summary' }}">

    {{-- Pages behind authentication are never worth indexing, and indexing one
         would put a customer's own view of their account in a search result. --}}
    @auth
        <meta name="robots" content="noindex, nofollow">
    @endauth

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- THE HEADER'S MENU IS ALPINE, AND ALPINE LIVES IN LIVEWIRE'S BUNDLE.
         Livewire only injects that bundle onto a page that renders a Livewire
         component, so a plain Blade page (How It Works, About, FAQs, Contact)
         got the header markup with nothing to run it: the menu was dead, and
         the [x-cloak] rule that also ships with Livewire's styles was missing
         too. Including the assets here makes every page that has the header
         able to run it. Livewire notices they were included by hand and does
         not inject them a second time. --}}
    @livewireStyles

    {{-- Truthful structured data only, and only where a page supplies it. --}}
    @isset($structuredData)
        <script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script>
    @endisset
</head>
<body class="flex min-h-full flex-col bg-slate-50 font-sans text-slate-900 antialiased">
    @include('partials.navigation')

    <main class="flex-1 py-8 sm:py-10">
        <x-container>
            @if (session('status'))
                <x-alert variant="info" class="mb-6">{{ session('status') }}</x-alert>
            @endif

            {{ $slot }}
        </x-container>
    </main>

    @include('partials.footer')

    @livewireScripts
</body>
</html>
