<?php

declare(strict_types=1);

namespace App\Livewire\Account;

use App\Domain\Auction\Services\AuctionClock;
use App\Domain\Marketplace\Queries\CustomerDashboardQuery;
use App\Models\Auction;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One customer's own account, at a glance.
 *
 * EVERY FIGURE COMES FROM THE TABLE THAT OWNS IT. The credit wallet for a
 * balance, the bid records for bidding, the auctions for wins, the orders for
 * what is owed, the deliveries for where things are. This component assembles
 * and renders; it computes nothing and stores nothing, so there is no second
 * source of truth to drift.
 *
 * TWO CREDIT FIGURES, NEVER ADDED TOGETHER. Available credits are in the
 * wallet and can be bid with. Committed credits have been consumed on bids and
 * are gone. A single number combining them would be meaningless -- half
 * spendable, half spent -- and the page says which is which.
 *
 * SCOPED TO THE SIGNED-IN CUSTOMER, in every query, through the query service.
 * Nothing on this page can be made to describe somebody else's account.
 */
#[Layout('components.layouts.app')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render(CustomerDashboardQuery $account, AuctionClock $clock): View
    {
        $user = auth()->user();
        $activeBids = $account->activeBids($user);

        return view('livewire.account.dashboard', [
            // Credits, kept apart and labelled. Never summed.
            'availableCredits' => $account->availableCredits($user),
            'creditsCommitted' => $account->creditsCommitted($user),

            'activeBids' => $activeBids,
            'activeBidCount' => $account->activeBidCount($user),
            // Whether this customer currently holds the highest bid on each.
            // From the projection, which is what a listing is for.
            'leading' => $activeBids
                ->mapWithKeys(fn (Auction $a): array => [$a->id => $account->isLeading($user, $a)])
                ->all(),
            'remaining' => $activeBids
                ->mapWithKeys(fn (Auction $a): array => [$a->id => $clock->secondsRemaining($a)])
                ->all(),

            'auctionsWon' => $account->auctionsWon($user),
            // The most urgent thing on the page: a settlement deadline runs,
            // and a winner who misses it forfeits.
            'awaitingSettlement' => $account->awaitingSettlement($user),
            'unpaidOrders' => $account->unpaidOrderCount($user),

            'recentOrders' => $account->recentOrders($user),
            'activeDeliveries' => $account->activeDeliveries($user),
            'deliveriesAwaitingAddress' => $account->deliveriesAwaitingAddress($user),

            'totalSpent' => $account->totalSpent($user),
            'unreadNotifications' => $user->unreadNotificationCount(),

            // A modest card, not a campaign. The platform is a shop first, and
            // referral figures should not out-shout what somebody came for.
            'referralsRewarded' => $account->referralsRewarded($user),
            'referralCreditsEarned' => $account->referralCreditsEarned($user),
        ]);
    }
}
