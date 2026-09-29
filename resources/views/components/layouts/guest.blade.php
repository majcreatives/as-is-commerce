<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

      @php
          $brand = config('app.name');
          $ownTitle = $title ?? null;
      @endphp
      <title>{{ $ownTitle === null || str_contains($ownTitle, $brand) ? $brand : $ownTitle.' — '.$brand }}</title>
      <meta name="robots" content="noindex, nofollow">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
  <body class="flex min-h-full flex-col bg-slate-50 font-sans text-slate-900 antialiased">
      <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:shadow-lg">Skip to content</a>

      <div class="flex flex-1 flex-col justify-center px-4 py-10 sm:px-6">
          <div class="mx-auto w-full {{ ($wide ?? false) ? 'max-w-3xl' : 'max-w-md' }}">
              <a href="{{ route('home') }}" class="mb-8 flex justify-center">
                  <x-logo />
              </a>

              @if (session('status'))
                  <x-alert variant="info" class="mb-6">{{ session('status') }}</x-alert>
              @endif

              {{-- This layout had no <main> landmark at all, so there was nothing
                   for a screen reader to jump to and no skip target to point at. --}}
              <main id="main">
                  {{ $slot }}
              </main>
          </div>
      </div>

    <footer class="py-6 text-center text-xs text-slate-500">
        &copy; {{ date('Y') }} {{ config('app.name') }}
    </footer>
</body>
</html>
