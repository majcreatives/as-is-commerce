<?php

declare(strict_types=1);

namespace App\Livewire\Catalog;

use App\Domain\Catalog\Actions\UpdateCartLine;
use App\Domain\Marketplace\Queries\ProductDiscoveryQuery;
use App\Domain\Orders\Actions\PlaceCartOrder;
use App\Domain\Orders\Services\CheckoutPricer;
use App\Domain\Shared\Money\Money;
use App\Enums\OrderSource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * The basket a customer reviews and pays for once.
 *
 * STILL NOT THE ORDER. Everything on this page is intent and server-side
 * preview: editing a quantity or removing a line reserves nothing, writes no
 * ledger and creates no order. The only moment commerce happens is "Place
 * order", which re-validates every line against the inventory ledger in one
 * atomic transaction (`PlaceCartOrder`).
 *
 * EVERY FIGURE THE PAGE SHOWS IS COMPUTED HERE ON THE SERVER. Per-line totals
 * come from the product rows, and the order preview (subtotal, delivery, tax,
 * Store Wallet portion, payable) comes from `CheckoutPricer::forCart()`. The
 * browser sends a line id and a "how many" -- never a price or a figure.
 */
#[Layout('components.layouts.app')]
final class CartPage extends Component
{
    /**
     * Editable quantities, keyed by cart line id.
     *
     * @var array<string|int, int>
     */
    public array $quantities = [];

    public function mount(): void
    {
        $this->authorize('checkout.create');
        $this->quantities = $this->lineQuantities();
    }

    /**
     * Apply every changed quantity in one go.
     *
     * Each line goes through the domain action, so growth re-runs the add
     * rules (purchasable, not auction-held, capped at available stock). A line
     * that cannot take the wanted quantity reports its own message and the
     * rest of the cart still updates.
     */
    public function updateQuantities(UpdateCartLine $update): void
    {
        $this->authorize('checkout.create');

        $lines = $this->cart()->items->keyBy('id');

        foreach ($this->quantities as $lineId => $quantity) {
            $line = $lines->get((int) $lineId);

            if ($line === null) {
                continue;
            }

            $wanted = max(0, (int) $quantity);

            if ($wanted === $line->quantity) {
                continue;
            }

            try {
                $update->handle(auth()->user(), $line, $wanted);
            } catch (DomainException $e) {
                $this->addError('quantities.'.$lineId, $e->getMessage());
            }
        }

        $this->quantities = $this->lineQuantities();
    }

    /**
     * Drop one line from the basket.
     */
    public function removeLine(UpdateCartLine $update, int $lineId): void
    {
        $this->authorize('checkout.create');

        $line = $this->cart()->items()->find($lineId);

        if ($line === null) {
            return;
        }

        try {
            $update->handle(auth()->user(), $line, 0);
        } catch (DomainException $e) {
            $this->addError('remove.'.$lineId, $e->getMessage());
        }

        $this->quantities = $this->lineQuantities();
    }

    /**
     * Empty the basket.
     */
    public function clearCart(UpdateCartLine $update): void
    {
        $this->authorize('checkout.create');

        foreach ($this->cart()->items as $line) {
            $update->handle(auth()->user(), $line, 0);
        }

        $this->quantities = $this->lineQuantities();
    }

    /**
     * Turn the basket into an order, atomically.
     */
    public function placeOrder(PlaceCartOrder $place): ?RedirectResponse
    {
        $this->authorize('checkout.create');

        try {
            $order = $place->handle(auth()->user());
        } catch (DomainException $e) {
            $this->addError('place', $e->getMessage());

            return null;
        }

        return redirect()->route('checkout.show', $order);
    }

    public function render(
        ProductDiscoveryQuery $products,
        CheckoutPricer $pricer,
    ): View {
        $lines = $this->cart()->items;

        $availability = $products->availabilityFor(
            $lines->map(fn (CartItem $line): Product => $line->product
                ?? throw new RuntimeException('A cart line must reference a product.'))
                ->values(),
        );

        $pricing = null;
        $pricingError = null;

        if ($lines->isNotEmpty()) {
            try {
                $pricing = $pricer->forCart(
                    $lines->map(fn (CartItem $line): array => [
                        'product' => $line->product,
                        'quantity' => $line->quantity,
                    ])->values()->all(),
                    auth()->user(),
                );
            } catch (DomainException $e) {
                // The cart may hold a line that can no longer be bought (it
                // ran out of stock, or entered an auction). The page still
                // renders -- with per-line totals and a clear message -- and
                // placement refuses authoritatively.
                $pricingError = $e->getMessage();
            }
        }

        return view('livewire.catalog.cart-page', [
            'cart' => $this->cart(),
            'lines' => $lines,
            'availability' => $availability,
            'pricing' => $pricing,
            'pricingError' => $pricingError,
            'payableOrder' => $this->payableOrder(),
            'lineSubtotals' => $lines->mapWithKeys(
                fn (CartItem $line): array => [
                    $line->id => Money::fromMinor(
                        $line->product->buyNowPrice()->minor * $line->quantity,
                        $line->product->currency,
                    ),
                ],
            ),
        ])->title('Your cart');
    }

    /**
     * A Shop checkout this customer still owes on, if one exists.
     *
     * The cart page is the single view of an unfinished Shop purchase, so once
     * the basket becomes an order the order stays visible here until it is
     * paid, cancelled or expired. Its lines are frozen and reserved, so they
     * are shown read-only. Only catalogue orders (`BuyNow`, no auction) belong
     * here: auction-linked checkouts run on their own rails. The items are
     * loaded because the blade renders each snapshot line.
     */
    private function payableOrder(): ?Order
    {
        return Order::query()
            ->where('user_id', auth()->id())
            ->where('source', OrderSource::BuyNow)
            ->whereNull('auction_id')
            ->awaitingPayment()
            ->where(function ($query) {
                $query->whereNull('payment_due_at')
                    ->orWhere('payment_due_at', '>', now());
            })
            ->latest('id')
            ->with(['items' => fn ($q) => $q->orderBy('product_id')->with('order')])
            ->first();
    }

    /**
     * The signed-in customer's cart, lines and products loaded.
     *
     * One cart per customer, created here if the customer has never added a
     * line yet: a cart row is non-financial and holds nothing, so its
     * existence only ever means "this basket is this customer's".
     */
    private function cart(): Cart
    {
        return Cart::query()
            ->firstOrCreate(['user_id' => auth()->id()])
            ->load(['items' => fn ($q) => $q->orderBy('product_id')->with('product')]);
    }

    /**
     * @return array<string|int, int>
     */
    private function lineQuantities(): array
    {
        return $this->cart()->items
            ->mapWithKeys(fn (CartItem $line) => [$line->id => $line->quantity])
            ->all();
    }
}
