<div>
    <x-admin.nav />

    <x-page-header
        title="Wallets"
        description="Inspect a user's credit and cash ledgers. Balances are derived from the ledger and cannot be edited directly." />

    <div class="mb-4">
        <x-input type="search" wire:model.live.debounce.300ms="search"
                 placeholder="Search by phone, email or name" class="max-w-md" />
    </div>

    <x-card :padded="false">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[44rem] text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">User</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Phone</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Credits</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Balance</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($users as $user)
                        <tr>
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-900">{{ $user->name ?? 'No name given' }}</span>
                                @if ($user->email)
                                    <span class="block text-xs text-slate-500">{{ $user->email }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 tabular-nums text-slate-600">
                                {{ app(\App\Domain\Shared\Phone\PhoneNumberNormalizer::class)->forDisplay($user->phone) }}
                            </td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">
                                {{ number_format($user->creditWallet?->balance ?? 0) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-slate-600">
                                {{ $user->cashWallet?->balance()->format() ?? '0.00' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <x-button href="{{ route('admin.wallets.show', $user) }}" wire:navigate
                                          variant="secondary" size="sm">Inspect</x-button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10">
                                <x-empty-state
                                    title="No users match this search"
                                    description="Search by phone number, email address or name." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    @if ($users->hasPages())
        <div class="mt-4">{{ $users->links() }}</div>
    @endif
</div>
