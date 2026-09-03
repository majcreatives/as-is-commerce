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
    <div class="flex flex-1 flex-col justify-center px-4 py-10 sm:px-6">
        <div class="mx-auto w-full max-w-md">
            <a href="{{ route('home') }}" class="mb-8 flex justify-center">
                <x-logo />
            </a>

            @if (session('status'))
                <x-alert variant="info" class="mb-6">{{ session('status') }}</x-alert>
            @endif

            {{ $slot }}
        </div>
    </div>

    <footer class="py-6 text-center text-xs text-slate-500">
        &copy; {{ date('Y') }} {{ config('app.name') }}
    </footer>
</body>
</html>
