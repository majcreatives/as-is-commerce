{{-- The one-time code email.

     Plain on purpose. The code is the message; nothing is more secure by
     adding links or buttons, and a wrong link in a verification mail would be
     worse than no link. The code alone never signs anybody in. --}}

<x-mail::message>
# {{ $purposeLabel }}

Use this one-time code to complete the action, it is valid for
{{ $expiresInMinutes }} minutes:

**{{ $code }}**

If you didn't request this, you can ignore this email — the code alone is
never enough to access your account.

Thanks,<br>
{{ settings()->getString('site_name', config('app.name')) }}
</x-mail::message>