<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
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
</body>
</html>
