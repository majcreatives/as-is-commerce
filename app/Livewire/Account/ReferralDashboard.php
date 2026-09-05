<?php

declare(strict_types=1);

namespace App\Livewire\Account;

use App\Domain\Referrals\Services\ReferralCodes;
use App\Domain\Referrals\Services\ReferralProgramme;
use App\Enums\ReferralStatus;
use App\Models\Referral;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A customer's own referrals.
 *
 * SCOPED IN THE QUERY, ALWAYS. Every read starts from the signed-in customer's
 * own relation, so there is no path by which somebody else's referrals could be
 * listed or counted.
 *
 * AND THE REFERRED CUSTOMERS ARE NEVER NAMED. A referrer sees that somebody
 * joined, whether they have bought anything, and what it earned -- not who they
 * are, what they bought, or when they last logged in. The person who followed a
 * link did not agree to be reported on.
 *
 * CREDITS, NEVER MONEY. Everything earned here is a count of platform credits.
 * The page says so, says they cannot be withdrawn, and never renders a cedis
 * figure -- because there is no cedis figure: a referral reward is not a
 * payout, a commission or a balance that could become cash.
 *
 * NOTHING ON THIS SCREEN ISSUES ANYTHING. There is no method here that grants
 * credits, and no value a request could send that would. Rewards happen when a
 * referred customer's payment is verified, on the server, and this page reports
 * what already occurred.
 */
#[Layout('components.layouts.app')]
#[Title('Invite friends')]
class ReferralDashboard extends Component
{
    use WithPagination;

    public function render(ReferralCodes $codes, ReferralProgramme $programme): View
    {
        $user = auth()->user();

        // Issued the first time somebody opens this page, and never again.
        $code = $codes->forUser($user);

        return view('livewire.account.referral-dashboard', [
            'code' => $code,
            'shareUrl' => $codes->shareUrl($code),
            'referrals' => $this->referrals(),
            'joined' => $this->countOf(),
            'qualified' => $this->countOf(ReferralStatus::Qualified),
            'rewarded' => $this->countOf(ReferralStatus::Rewarded),
            // Summed from the snapshots on the referrals themselves, which are
            // what was actually granted. Never from a wallet balance: credits
            // move for a dozen reasons, and a balance says nothing about how
            // many people somebody introduced.
            'creditsEarned' => (int) $user->referralsMade()
                ->where('status', ReferralStatus::Rewarded)
                ->sum('reward_credits'),
            'programmeEnabled' => $programme->isEnabled(),
            'rewardCredits' => $programme->rewardCredits(),
            'cap' => $programme->maximumRewardsPerReferrer(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Referral>
     */
    private function referrals(): LengthAwarePaginator
    {
        return auth()->user()->referralsMade()
            ->latest('id')
            ->paginate(10);
    }

    private function countOf(?ReferralStatus $status = null): int
    {
        return auth()->user()->referralsMade()
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->count();
    }
}
