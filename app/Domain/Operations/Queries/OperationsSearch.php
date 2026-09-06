<?php

declare(strict_types=1);

namespace App\Domain\Operations\Queries;

use App\Domain\Shared\Phone\PhoneNumberNormalizer;
use App\Models\Auction;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Referral;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One box that finds the thing somebody is holding a reference to.
 *
 * WHAT SUPPORT ACTUALLY HAS is a string: an order number read off an email, a
 * provider reference from a Paystack dashboard, a delivery reference from a
 * package, a phone number from a call. They should not have to know which of
 * eight screens that string belongs to.
 *
 * BOUNDED IN EVERY DIRECTION. The term is trimmed and length-capped before it
 * reaches a query, each kind of result is limited, and nothing here can return
 * an unbounded set. A search box is the easiest place on a platform to make the
 * database do arbitrary work, and an admin search that could be used that way
 * is a denial of service with a login form in front of it.
 *
 * PHONE NUMBERS ARE NORMALIZED FIRST. Numbers are stored E.164, so somebody
 * typing 024 123 4567 has to be turned into +233241234567 before it will match
 * anything -- otherwise the one search support most needs silently returns
 * nothing.
 *
 * READ ONLY, AND AUTHORIZATION IS THE CALLER'S JOB. This finds records; the
 * screen that shows them enforces who may see what, and each result links to a
 * page that checks its own permission.
 */
class OperationsSearch
{
    /** Longer than any reference this platform issues. */
    public const MAX_LENGTH = 64;

    /** Per kind of result. A search is a lookup, not an export. */
    public const PER_TYPE = 10;

    public function __construct(
        private readonly PhoneNumberNormalizer $phones,
    ) {}

    /**
     * Everything matching, grouped by what it is.
     *
     * @return array<string, Collection<int, mixed>>
     */
    public function search(string $term): array
    {
        $term = $this->clean($term);

        // The floor is enforced here rather than only on the screen. A single
        // letter matches most of the catalogue and half the customer table,
        // and a caller that forgot to check would turn this into an export.
        if ($this->isTooShort($term)) {
            return [];
        }

        $like = '%'.$term.'%';

        $results = [
            'orders' => $this->orders($like),
            'customers' => $this->customers($term, $like),
            'products' => $this->products($like),
            'auctions' => $this->auctions($term, $like),
            'payments' => $this->payments($like),
            'refunds' => $this->refunds($like),
            'deliveries' => $this->deliveries($like),
            'referrals' => $this->referrals($term, $like),
        ];

        // Empty groups are noise on a results page.
        return array_filter($results, fn ($group): bool => $group->isNotEmpty());
    }

    public function isTooShort(string $term): bool
    {
        return mb_strlen($this->clean($term)) < 2;
    }

    /**
     * Trimmed and capped before anything sees it.
     */
    public function clean(string $term): string
    {
        return mb_substr(trim($term), 0, self::MAX_LENGTH);
    }

    /**
     * @return Collection<int, Order>
     */
    private function orders(string $like)
    {
        return Order::query()
            ->with('user')
            ->where('order_number', 'like', $like)
            ->latest('id')
            ->limit(self::PER_TYPE)
            ->get();
    }

    /**
     * Customers by name, email or phone.
     *
     * The phone is normalized first, so a number typed the way a customer says
     * it out loud still matches what is stored.
     *
     * @return Collection<int, User>
     */
    private function customers(string $term, string $like)
    {
        $e164 = $this->phones->normalize($term);

        return User::query()
            ->where(fn ($q) => $q
                ->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->when($e164 !== null, fn ($inner) => $inner->orWhere('phone', $e164))
                ->orWhere('referral_code', mb_strtoupper($term)))
            ->latest('id')
            ->limit(self::PER_TYPE)
            ->get();
    }

    /**
     * @return Collection<int, Product>
     */
    private function products(string $like)
    {
        return Product::query()
            ->with('brand')
            ->where(fn ($q) => $q
                ->where('sku', 'like', $like)
                ->orWhere('slug', 'like', $like)
                ->orWhere('name', 'like', $like))
            ->latest('id')
            ->limit(self::PER_TYPE)
            ->get();
    }

    /**
     * @return Collection<int, Auction>
     */
    private function auctions(string $term, string $like)
    {
        return Auction::query()
            ->with('product')
            ->where(fn ($q) => $q
                ->when(ctype_digit($term), fn ($inner) => $inner->orWhere('id', (int) $term))
                ->orWhereHas('product', fn ($p) => $p
                    ->where('sku', 'like', $like)
                    ->orWhere('name', 'like', $like)))
            ->latest('id')
            ->limit(self::PER_TYPE)
            ->get();
    }

    /**
     * @return Collection<int, OrderPayment>
     */
    private function payments(string $like)
    {
        return OrderPayment::query()
            ->with('order')
            ->where('provider_reference', 'like', $like)
            ->latest('id')
            ->limit(self::PER_TYPE)
            ->get();
    }

    /**
     * @return Collection<int, Refund>
     */
    private function refunds(string $like)
    {
        return Refund::query()
            ->with('order')
            ->where('provider_reference', 'like', $like)
            ->latest('id')
            ->limit(self::PER_TYPE)
            ->get();
    }

    /**
     * @return Collection<int, Delivery>
     */
    private function deliveries(string $like)
    {
        return Delivery::query()
            ->with('order')
            ->where(fn ($q) => $q
                ->where('reference', 'like', $like)
                ->orWhere('tracking_reference', 'like', $like))
            ->latest('id')
            ->limit(self::PER_TYPE)
            ->get();
    }

    /**
     * @return Collection<int, Referral>
     */
    private function referrals(string $term, string $like)
    {
        return Referral::query()
            ->with(['referrer', 'referred'])
            ->where('code_used', mb_strtoupper($term))
            ->latest('id')
            ->limit(self::PER_TYPE)
            ->get();
    }
}
