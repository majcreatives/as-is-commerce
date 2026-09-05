@props(['seconds', 'endsAt' => null])

{{-- How long is left, as the server saw it when this page was rendered.

     INFORMATIONAL, AND NOTHING ELSE. This number decides nothing. An auction
     ends when its own `ends_at` timestamp says so, whether or not any browser
     is open, and the sweep that closes it runs on the server every minute.

     So when this reaches zero the page does not announce a result -- it has
     no way of knowing one. It says the auction is closing and refreshes, and
     the authoritative state is whatever comes back. --}}

@php
    $left = max(0, (int) $seconds);
    $days = intdiv($left, 86400);
    $hours = intdiv($left % 86400, 3600);
    $minutes = intdiv($left % 3600, 60);
    $secs = $left % 60;

    $text = match (true) {
        $left <= 0 => 'Closing',
        $days > 0 => $days.'d '.$hours.'h',
        $hours > 0 => $hours.'h '.$minutes.'m',
        $minutes > 0 => $minutes.'m '.$secs.'s',
        default => $secs.'s',
    };
@endphp

<span {{ $attributes }}
      @if ($endsAt) title="Closes {{ $endsAt->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y, H:i') }}" @endif>
    {{ $text }}
</span>
