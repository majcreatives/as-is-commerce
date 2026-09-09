<?php

declare(strict_types=1);

namespace App\Domain\StoreWallet\Services;

use App\Domain\Auction\Contracts\AuctionLossCompensation;
use App\Enums\StoreWalletTransactionType;
use App\Models\Auction;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Gives losing bidders back what their purchased credits actually cost them,
 * as Store Wallet value.
 *
 * THE RULE. When an auction ends and somebody else ends up with the product,
 * every other bidder's consumed PURCHASED credits are valued at the price
 * those exact credits were bought at, and that amount is issued as Store
 * Wallet credit.
 *
 *     Store Wallet issued = sum over lots of
 *                           consumed credits from that lot
 *                           x that lot's own acquisition rate
 *
 * WHAT DOES NOT COME BACK. The credits themselves. They were consumed when the
 * bid was accepted and they stay consumed -- this is not a refund of credits,
 * it is a separate grant of purchasing power. A losing bidder's credit balance
 * is exactly what it was a moment before the auction closed.
 *
 * FREE CREDITS PRODUCE NOTHING. Promotional, referral and adjustment credits
 * cost the customer no money. Valuing them at anything would manufacture a
 * cash liability out of a gift, and would make giving credits away an
 * expensive thing for the platform to do. They are burned, exactly as before.
 *
 * WHO IS EXCLUDED, and why each exclusion is not a technicality:
 *
 *   the winner        They won. Their credits bought them the thing they were
 *                     bidding for, and they owe the settlement amount for it.
 *
 *   a Buy Now buyer   The value of their consumed credits already came off
 *                     what they paid. Issuing it again would hand them the
 *                     same value twice.
 *
 * ISSUED ONCE, ENFORCED BY THE DATABASE. The idempotency key names the auction
 * and the user, and the column is unique. Two sweeps closing the same auction
 * together, a retried worker, a replayed webhook and an administrator re-running
 * a closure all converge on the row that already exists. Nothing here checks
 * first and writes second, because that pattern loses a race it cannot see.
 *
 * ORDER OF PROCESSING. Every losing bidder's wallet is resolved first, and
 * they are then processed in ascending WALLET id -- not user id, which is a
 * different order and only accidentally the same one. Two closures whose
 * bidders overlap therefore request the same rows in the same sequence and
 * queue rather than deadlock, which is the rule the credit ledger already
 * follows for lots.
 */
class AuctionLossCompensator implements AuctionLossCompensation
{
    public function __construct(
        private readonly ConsumedCreditValuation $valuation,
        private readonly StoreWalletLedgerService $wallets,
    ) {}

    /**
     * @return array<int, int>
     */
    public function compensateLosers(Auction $auction, ?int $acquiredByUserId): array
    {
        $issued = [];

        foreach ($this->losersByWallet($auction, $acquiredByUserId) as [$user, $wallet]) {
            $amount = $this->compensate($auction, $user, $wallet);

            if ($amount > 0) {
                $issued[$user->id] = $amount;
            }
        }

        if ($issued !== []) {
            Log::info('Store Wallet issued to losing bidders', [
                'operation' => 'store_wallet.auction_loss',
                'auction_id' => $auction->id,
                'acquired_by_user_id' => $acquiredByUserId,
                'recipients' => count($issued),
                'total_minor' => array_sum($issued),
            ]);
        }

        return $issued;
    }

    /**
     * Everyone who bid and did not acquire the product, paired with their
     * wallet, in the order those wallet rows must be locked.
     *
     * The wallets are resolved -- and created, for a first-time recipient --
     * before any of them is locked, so the sort key is known in advance rather
     * than emerging as rows happen to be touched.
     *
     * @return list<array{User, StoreWallet}>
     */
    private function losersByWallet(Auction $auction, ?int $acquiredByUserId): array
    {
        $pairs = [];

        foreach ($this->valuation->biddersOn($auction) as $userId) {
            if ($userId === $acquiredByUserId) {
                // They got the product. Their consumed credits are accounted
                // for by the acquisition itself, not by a second grant.
                continue;
            }

            $user = User::find($userId);

            if ($user === null) {
                // The bidder's account is gone. Nothing to credit, and
                // inventing a wallet for a deleted user would be worse than
                // recording nothing.
                continue;
            }

            $pairs[] = [$user, $this->wallets->walletFor($user, $auction->currency)];
        }

        usort($pairs, fn (array $a, array $b): int => $a[1]->getKey() <=> $b[1]->getKey());

        return $pairs;
    }

    /**
     * Value and issue for one losing bidder.
     *
     * Returns the minor units issued, or zero when their consumed credits were
     * worth nothing -- an entirely ordinary outcome for somebody who bid with
     * referral credits.
     */
    private function compensate(Auction $auction, User $user, StoreWallet $wallet): int
    {
        $valuation = $this->valuation->forAuction($auction, $user->id, $auction->currency);

        if (! $valuation->total->isPositive()) {
            return 0;
        }

        $transaction = $this->wallets->issue(
            wallet: $wallet,
            valuation: $valuation,
            type: StoreWalletTransactionType::AuctionLossCompensation,
            idempotencyKey: self::keyFor($auction, $user->id),
            reference: $auction,
            description: "Value of purchased credits used bidding on auction #{$auction->id}.",
            metadata: [
                'auction_id' => $auction->id,
                'product_id' => $auction->product_id,
                'closure_reason' => $auction->closure_reason?->value,
            ],
        );

        return $transaction === null ? 0 : abs($transaction->amount_minor);
    }

    /**
     * The key that makes one auction and one bidder produce one issuance.
     *
     * Derived entirely from persisted identities. Nothing about the time of
     * day, the worker, the request or the number of attempts goes into it, so
     * every retry computes the same key and collides with the row that is
     * already there.
     */
    public static function keyFor(Auction $auction, int $userId): string
    {
        return "auction-loss:{$auction->getKey()}:{$userId}";
    }

    /**
     * What was already issued to this bidder for this auction, if anything.
     *
     * For screens and tests that want to show or assert the issuance without
     * re-deriving the key.
     */
    public function issuanceFor(Auction $auction, int $userId): ?StoreWalletTransaction
    {
        return StoreWalletTransaction::where('idempotency_key', self::keyFor($auction, $userId))->first();
    }
}
