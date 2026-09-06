<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Customers;

use App\Domain\Credit\Services\CreditLedgerService;
use App\Domain\Marketplace\Queries\CustomerDashboardQuery;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One customer, for support.
 *
 * WHAT SOMEBODY ON A CALL ACTUALLY NEEDS: what this person bought, what they
 * owe, where their packages are, what happened to their money, and whether
 * anything of theirs is stuck. Assembled from the tables that own each fact,
 * because support answering "what happened to my order" from a stale summary
 * would give the wrong answer confidently.
 *
 * WHAT IT DELIBERATELY DOES NOT SHOW. No password hash, no remember token, no
 * OTP material, no payment credential, no provider secret. The model hides the
 * first two, and nothing here reaches for the rest -- a support screen is
 * exactly where a credential leaks if anybody puts one on it.
 *
 * READ ONLY, ENTIRELY. There is no method on this component that changes
 * anything: no status control, no balance field, no order action, no credit
 * adjustment. Every one of those exists already on the screen that owns it,
 * behind the permission that governs it, and duplicating one here would be a
 * second implementation of a rule.
 */
#[Layout('components.layouts.app')]
class CustomerDetail extends Component
{
    public User $customer;

    public function mount(User $user): void
    {
        $this->authorize('customers.view');

        $this->customer = $user;
    }

    public function render(
        CustomerDashboardQuery $account,
        CreditLedgerService $credits,
    ): View {
        $user = $this->customer;
        $wallet = $credits->walletFor($user);

        return view('livewire.admin.customers.customer-detail', [
            'customer' => $user,

            // Credits, kept as two figures and never summed: one is spendable
            // and one is spent, and a total would mean neither.
            'availableCredits' => $wallet->spendableBalance(),
            'creditsCommitted' => $account->creditsCommitted($user),
            'wallet' => $wallet,
            // The ledger itself lives on the wallet screen, which already
            // exists. This links there rather than reimplementing it.
            'recentCredits' => $wallet->transactions()
                ->with('lots')
                ->latest('id')
                ->limit(10)
                ->get(),

            'orders' => $user->orders()
                ->with(['items', 'delivery', 'refunds'])
                ->latest('id')
                ->limit(20)
                ->get(),
            'unpaidOrders' => $account->unpaidOrderCount($user),
            'totalSpent' => $account->totalSpent($user),

            'deliveries' => $user->deliveries()
                ->with('order')
                ->latest('id')
                ->limit(10)
                ->get(),

            'bids' => Bid::query()
                ->where('user_id', $user->id)
                ->with('auction.product')
                ->latest('id')
                ->limit(20)
                ->get(),
            'auctionsWon' => Auction::query()->where('winner_user_id', $user->id)->count(),
            'auctionsBidOn' => Auction::query()
                ->whereHas('bids', fn ($q) => $q->where('user_id', $user->id))
                ->count(),
            'liveAuctions' => Auction::query()
                ->whereIn('status', [AuctionStatus::Live, AuctionStatus::Closing])
                ->whereHas('bids', fn ($q) => $q->where('user_id', $user->id))
                ->count(),

            // Who introduced them, and who they introduced. Both sides matter
            // to somebody investigating an abuse report.
            'referredBy' => $user->referredBy()->with('referrer')->first(),
            'referralsMade' => $user->referralsMade()->latest('id')->limit(10)->get(),

            'notifications' => $user->notifications()->limit(10)->get(),
        ])->title($user->name ?? 'Customer');
    }
}
