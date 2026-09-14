{{-- Customers, for support.

     Finding somebody, not browsing everybody. Read-only: nothing on this screen
     changes an account, a balance or an order. --}}

<x-admin.shell>

    <x-page-header
        title="Customers"
        description="Find the person on the call. Search by name, phone, email or referral code." />

    <x-card class="mb-6">
        <x-field label="Search" name="search">
            <input type="search" id="search" wire:model.live.debounce.400ms="search" maxlength="64"
                   autocomplete="off"
                   placeholder="Name, 024 123 4567, email, referral code"
                   class="block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm">
        </x-field>

        <p class="mt-2 text-xs text-slate-500">
            Numbers are stored in international form, and a number typed the way a customer says
            it is normalized before matching.
        </p>
    </x-card>

    <x-card :padded="false">
        @if ($customers->isEmpty())
            <div class="p-5">
                <x-empty-state
                    title="No customer found"
                    :description="trim($search) === ''
                        ? 'Nobody has registered yet.'
                        : 'Nobody matches that name, number, email or referral code.'" />
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3 font-semibold">Customer</th>
                            <th class="px-5 py-3 font-semibold">Phone</th>
                            <th class="px-5 py-3 font-semibold">Referral code</th>
                            <th class="px-5 py-3 font-semibold">Orders</th>
                            <th class="px-5 py-3 font-semibold">Joined</th>
                            <th class="px-5 py-3"><span class="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($customers as $customer)
                            <tr>
                                <td class="px-5 py-3">
                                    <p class="font-semibold text-slate-900">{{ $customer->name }}</p>
                                    @if ($customer->email)
                                        <p class="text-xs text-slate-500">{{ $customer->email }}</p>
                                    @endif
                                </td>

                                <td class="px-5 py-3 text-slate-700">{{ $customer->phone }}</td>

                                <td class="px-5 py-3 font-mono text-xs text-slate-600">
                                    {{ $customer->referral_code ?? '—' }}
                                </td>

                                <td class="px-5 py-3 tabular-nums text-slate-700">
                                    {{ number_format($customer->orders_count) }}
                                </td>

                                <td class="px-5 py-3 text-slate-500">
                                    {{ $customer->created_at?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y') }}
                                </td>

                                <td class="px-5 py-3 text-right">
                                    <x-button size="sm" variant="ghost"
                                              href="{{ route('admin.customers.show', $customer) }}" wire:navigate>
                                        Open
                                    </x-button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-100 px-5 py-4">{{ $customers->links() }}</div>
        @endif
    </x-card>
</x-admin.shell>
