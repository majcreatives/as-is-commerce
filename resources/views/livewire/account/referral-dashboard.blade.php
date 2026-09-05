{{-- A customer's own referrals.

     Referred customers are never named. A referrer sees that somebody joined
     and whether it earned anything — not who they are or what they bought.

     Everything earned is a count of credits. There is no cedis figure on this
     page, because a referral reward is not a payout. --}}

<div>
    <x-page-header
        title="Invite friends"
        description="Share your link. When someone you invite makes their first purchase, you get credits." />

    @unless ($programmeEnabled)
        {{-- Said plainly rather than showing a reward figure the platform is
             not currently honouring. --}}
        <x-alert variant="info" class="mb-6">
            Referral rewards are paused at the moment. You can still share your link, and we will
            tell you when rewards are running again.
        </x-alert>
    @endunless

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="Your referral link">
                <div x-data="{ copied: false }">
                    <label for="referral-link" class="text-sm font-medium text-slate-700">
                        Share this link
                    </label>

                    <div class="mt-2 flex flex-wrap gap-2">
                        <input id="referral-link" type="text" readonly
                               value="{{ $shareUrl }}"
                               x-ref="link"
                               class="block w-full min-w-0 flex-1 rounded-lg border-0 bg-slate-50 px-3 py-2.5 font-mono text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-brand-600">

                        <x-button type="button"
                                  x-on:click="
                                      navigator.clipboard.writeText($refs.link.value);
                                      copied = true;
                                      setTimeout(() => copied = false, 2000);
                                  ">
                            <span x-show="! copied">Copy link</span>
                            <span x-show="copied" x-cloak>Copied</span>
                        </x-button>
                    </div>

                    {{-- Announced to a screen reader rather than only shown. --}}
                    <p class="sr-only" role="status" x-text="copied ? 'Referral link copied.' : ''"></p>
                </div>

                <div class="mt-5 border-t border-slate-100 pt-4">
                    <p class="text-sm text-slate-600">Or share your code</p>
                    <p class="mt-1 font-mono text-2xl font-bold tracking-widest text-slate-900">
                        {{ $code }}
                    </p>
                </div>
            </x-card>

            <x-card title="People you invited" :padded="false">
                @if ($referrals->isEmpty())
                    <div class="p-5">
                        <x-empty-state
                            title="No one yet"
                            description="Share your link with someone who might want something in the shop." />
                    </div>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($referrals as $referral)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                                <div>
                                    {{-- Deliberately anonymous. The person who
                                         followed the link did not agree to be
                                         reported on. --}}
                                    <p class="text-sm font-semibold text-slate-900">
                                        Someone joined
                                        {{ $referral->attributed_at->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y') }}
                                    </p>

                                    @if ($referral->isRewarded())
                                        <p class="mt-1 text-sm text-slate-600">
                                            You earned <x-credits :amount="$referral->reward_credits" />
                                            on
                                            {{ $referral->rewarded_at?->timezone(settings()->getString('display_timezone', 'UTC'))->format('j M Y') }}
                                        </p>
                                    @endif
                                </div>

                                <x-badge :classes="$referral->status->badgeClasses()">
                                    {{ $referral->status->customerLabel() }}
                                </x-badge>
                            </li>
                        @endforeach
                    </ul>

                    <div class="border-t border-slate-100 px-5 py-3">{{ $referrals->links() }}</div>
                @endif
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Credits earned</p>
                <p class="mt-1 text-3xl font-bold tabular-nums text-slate-900">
                    {{ number_format($creditsEarned) }}
                </p>
                {{-- The material condition, next to the number rather than
                     buried at the bottom of the page. --}}
                <p class="mt-1 text-xs text-slate-500">
                    Bidding credits, not cash. They cannot be withdrawn or transferred.
                </p>
            </x-card>

            <x-card title="Your referrals">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-600">Joined</dt>
                        <dd class="font-semibold tabular-nums text-slate-900">{{ number_format($joined) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-600">Made a purchase</dt>
                        <dd class="font-semibold tabular-nums text-slate-900">
                            {{ number_format($qualified + $rewarded) }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-600">Rewarded</dt>
                        <dd class="font-semibold tabular-nums text-slate-900">{{ number_format($rewarded) }}</dd>
                    </div>
                </dl>
            </x-card>

            {{-- The whole promise, in full, where somebody will actually read
                 it. No hidden conditions. --}}
            <x-card title="How it works">
                <ol class="space-y-3 text-sm text-slate-600">
                    <li>1. Share your link with someone who has not used the platform.</li>
                    <li>2. They create an account through it.</li>
                    <li>
                        3. When they complete their first purchase — bought outright or an auction
                        they won and settled —
                        @if ($programmeEnabled && $rewardCredits > 0)
                            you receive <x-credits :amount="$rewardCredits" class="font-semibold" />.
                        @else
                            you receive the configured referral reward.
                        @endif
                    </li>
                </ol>

                <div class="mt-4 space-y-2 border-t border-slate-100 pt-4 text-xs text-slate-500">
                    <p>A signup on its own does not earn anything.</p>
                    <p>You cannot refer yourself, and each person can only be referred once.</p>
                    @if ($cap > 0)
                        <p>You can earn a reward for up to {{ number_format($cap) }} people.</p>
                    @endif
                    <p>
                        Referral credits are ordinary bidding credits. They are not money, cannot be
                        withdrawn, and cannot be sent to another customer. Credits you spend on bids
                        are consumed like any other.
                    </p>
                </div>
            </x-card>
        </div>
    </div>
</div>
