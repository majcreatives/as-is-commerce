{{-- A customer's own notifications.

     Everything here is scoped to the signed-in user in the query itself.
     Credits appear as counts and money with a symbol, exactly as they do
     everywhere else. --}}

<div>
    <x-page-header
        title="Notifications"
        description="Everything the platform has told you, newest first." />

    <div class="mb-6 flex flex-wrap items-center gap-3">
        @foreach (['all' => 'All', 'unread' => 'Unread'] as $value => $label)
            <button type="button" wire:click="$set('filter', '{{ $value }}')"
                    class="rounded-lg px-3 py-1.5 text-sm font-semibold transition
                           {{ $filter === $value ? 'bg-brand-700 text-white' : 'text-brand-800 hover:bg-brand-50' }}">
                {{ $label }}
                @if ($value === 'unread' && $unreadCount > 0)
                    <span class="ml-1 rounded-full bg-white/20 px-1.5 text-xs">{{ $unreadCount }}</span>
                @endif
            </button>
        @endforeach

        @if ($unreadCount > 0)
            <x-button variant="ghost" size="sm" wire:click="markAllRead" class="ml-auto">
                Mark all as read
            </x-button>
        @endif
    </div>

    @if ($notifications->isEmpty())
        <x-empty-state
            title="{{ $filter === 'unread' ? 'Nothing unread' : 'No notifications yet' }}"
            description="Bid on an auction or buy something, and what happens next will appear here." />
    @else
        <x-card :padded="false">
            <ul class="divide-y divide-slate-100">
                @foreach ($notifications as $notification)
                    <li class="{{ $notification->isUnread() ? 'bg-brand-50/40' : '' }} px-5 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($notification->isUnread())
                                        <span class="size-2 shrink-0 rounded-full bg-brand-600"
                                              aria-label="Unread"></span>
                                    @endif

                                    <p class="font-semibold text-slate-900">{{ $notification->title }}</p>

                                    @if ($notification->eventType())
                                        <x-badge :classes="$notification->eventType()->badgeClasses()">
                                            {{ $notification->eventType()->label() }}
                                        </x-badge>
                                    @endif
                                </div>

                                <p class="mt-1 text-sm text-slate-600">{{ $notification->message }}</p>

                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $notification->created_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y, H:i') }}
                                </p>
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center gap-2">
                                @if ($notification->action_url)
                                    {{-- A link, not a capability: the page it reaches
                                         does its own authorization. --}}
                                    <x-button variant="secondary" size="sm"
                                              href="{{ $notification->action_url }}" wire:navigate>
                                        {{ $notification->action_label ?? 'Open' }}
                                    </x-button>
                                @endif

                                @if ($notification->isUnread())
                                    <x-button variant="ghost" size="sm"
                                              wire:click="markRead('{{ $notification->id }}')">
                                        Mark read
                                    </x-button>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="border-t border-slate-100 px-5 py-4">{{ $notifications->links() }}</div>
        </x-card>
    @endif
</div>
