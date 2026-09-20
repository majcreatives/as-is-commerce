@php
    // Read from settings, which administrators own, and never hard-coded: a
    // support number in a template is a number that outlives the person who
    // answers it. Both are blank until somebody sets them, and blank means
    // hidden rather than a placeholder that looks real.
    $email = trim((string) settings()->getString('support_email', ''));
    $phone = trim((string) settings()->getString('support_phone', ''));

    // A tel: link wants digits and a leading plus, not the spaces somebody
    // typed it with.
    $phoneHref = $phone === '' ? '' : preg_replace('/[^\d+]/', '', $phone);
@endphp

<x-layouts.app title="Contact us"
               :description="'How to reach '.config('app.name').'.'">

    <x-page-header
        title="Contact us"
        description="How to reach us." />

    @if ($email === '' && $phone === '')
        {{-- Honest, and not a form that goes nowhere. There is no inbox behind
             this page to send a message to, so it does not pretend to have
             one. --}}
        <x-empty-state
            title="Contact details are not published yet"
            description="They will appear here once they are set up. In the meantime, our FAQs answer the common questions.">
            <x-button href="{{ route('faqs') }}" wire:navigate variant="secondary" size="sm">Read the FAQs</x-button>
        </x-empty-state>
    @else
        <x-card title="Reach us">
            <dl class="space-y-4 text-sm">
                @if ($phone !== '')
                    <div>
                        <dt class="text-slate-500">Phone</dt>
                        <dd class="mt-0.5">
                            <a href="tel:{{ $phoneHref }}" class="font-semibold text-brand-800 underline">{{ $phone }}</a>
                        </dd>
                    </div>
                @endif

                @if ($email !== '')
                    <div>
                        <dt class="text-slate-500">Email</dt>
                        <dd class="mt-0.5">
                            <a href="mailto:{{ $email }}" class="font-semibold text-brand-800 underline">{{ $email }}</a>
                        </dd>
                    </div>
                @endif
            </dl>

            <p class="mt-5 text-sm text-slate-600">
                If you are asking about an order, have your order number ready. You will find it on
                your orders page once you are signed in.
            </p>
        </x-card>

        <p class="mt-6 text-sm text-slate-600">
            Looking for a quick answer? Try the
            <a href="{{ route('faqs') }}" wire:navigate class="font-semibold text-brand-800 underline">FAQs</a>.
        </p>
    @endif
</x-layouts.app>
