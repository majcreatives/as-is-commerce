{{-- What a customer wants to hear about.

     Only optional categories are switchable. Anything about money that has
     moved or an order that needs attention is always sent, and the card says
     so rather than leaving somebody to discover it. --}}

<x-card title="Notifications"
        subtitle="Choose what we tell you about, and how.">
    @if (session('notification-preferences'))
        <x-alert variant="success" class="mb-5">{{ session('notification-preferences') }}</x-alert>
    @endif

    <form wire:submit="save" class="space-y-5">
        @foreach ($categories as $category)
            <div class="rounded-lg border border-slate-200 p-4">
                <p class="text-sm font-semibold text-slate-900">{{ $category->label() }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ $category->description() }}</p>

                <div class="mt-3 flex flex-wrap gap-5">
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox"
                               wire:model="preferences.{{ $category->value }}.in_app"
                               class="rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                        In the app
                    </label>

                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox"
                               wire:model="preferences.{{ $category->value }}.email"
                               class="rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                        By email
                    </label>
                </div>
            </div>
        @endforeach

        <x-alert variant="info">
            Payments, settlements and anything needing your attention are always sent. We will not
            quietly stop telling you that we have your money.
        </x-alert>

        <x-button type="submit">Save preferences</x-button>
    </form>
</x-card>
