{{-- The email form of a notification.

     Deliberately plain. It carries the same title and message the customer
     sees in the application, and a link back into it -- never payment details,
     never credentials, and never anybody else's information. --}}

<x-mail::message>
# {{ $title }}

{{ $body }}

@if ($actionUrl)
<x-mail::button :url="$actionUrl">
{{ $actionLabel ?? 'View in your account' }}
</x-mail::button>
@endif

Thanks,<br>
{{ settings()->getString('site_name', config('app.name')) }}
</x-mail::message>
