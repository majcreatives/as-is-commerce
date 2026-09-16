<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Orders\ValueObjects\CheckoutPricing;
use App\Domain\Shared\Money\Money;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 *
 * Builds an order row directly, for tests about a state rather than about how
 * that state is reached.
 *
 * IT DOES NOT RESERVE STOCK, TAKE PAYMENT OR TOUCH AN AUCTION. Those are the
 * work of the checkout and fulfilment actions, and an order that skipped them
 * is a state the application never produces. Any test about payment,
 * inventory or the races between them should go through
 * `StartBuyNowCheckout` / `StartSettlementCheckout`, which is what the
 * `buyNowCheckout()` and `settlementCheckout()` test helpers do.
 *
 * The pricing snapshot is built from the same value object production uses, so
 * a factory-made order still adds up: subtotal less discount plus delivery and
 * tax equals total, and a database CHECK constraint will refuse it otherwise.
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => 'AIC-O-'.now()->format('Ymd').'-'.strtoupper(Str::random(10)),
            'user_id' => User::factory(),
            'source' => OrderSource::BuyNow,
            'status' => OrderStatus::PendingPayment,
            'currency' => 'GHS',
            'subtotal_minor' => 550_000,
            'discount_minor' => 0,
            'delivery_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 550_000,
            'discount_credits' => 0,
            'store_wallet_applied_minor' => 0,
            'payable_minor' => 550_000,
            'holds_reservation' => false,
            'placed_at' => Carbon::now(),
            'payment_due_at' => Carbon::now()->addMinutes(30),
        ];
    }

    /**
     * Lines to create with the order, as [{product, quantity?, price_minor?}?].
     * When empty the order gets the default single line at its own subtotal,
     * which keeps older fakes intact. When set, the order's pricing columns
     * and snapshot are recomputed to be the sum of those lines, because the
     * database CHECK constraint refuses an order that does not add up.
     *
     * @var list<array{product: Product, quantity?: int, price_minor?: int}>
     */
    public array $lines = [];

    /**
     * Build the pricing snapshot from whatever the amounts ended up as, and
     * attach the lines the order was asked for.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Order $order): void {
            if (isset($order->pricing_snapshot)) {
                return;
            }

            $money = fn (int $minor): Money => Money::fromMinor($minor, $order->currency);

            $order->pricing_snapshot = (new CheckoutPricing(
                source: $order->source,
                subtotal: $money($order->subtotal_minor),
                discount: $money($order->discount_minor),
                delivery: $money($order->delivery_minor),
                tax: $money($order->tax_minor),
                total: $money($order->total_minor),
                discountCredits: $order->discount_credits,
                storeWalletApplied: $money($order->store_wallet_applied_minor),
                payable: $money($order->payable_minor),
            ))->toArray();
        })->afterCreating(function (Order $order): void {
            if ($order->items()->exists()) {
                return;
            }

            $lines = $this->lines !== [] ? $this->lines : [
                [
                    'product' => Product::factory()->active()->pricedAt($order->subtotal_minor)->create(),
                    'quantity' => 1,
                    'price_minor' => $order->subtotal_minor,
                    'discount_minor' => $order->discount_minor,
                ],
            ];

            $subtotalMinor = 0;
            $discountMinor = 0;

            foreach ($lines as $line) {
                $product = $line['product'];
                $quantity = $line['quantity'] ?? 1;
                $unitPriceMinor = $line['price_minor'] ?? $product->buyNowPrice()->minor;
                $lineDiscountMinor = $line['discount_minor'] ?? 0;
                $lineTotalMinor = ($unitPriceMinor * $quantity) - $lineDiscountMinor;

                $subtotalMinor += $unitPriceMinor * $quantity;
                $discountMinor += $lineDiscountMinor;

                $item = new OrderItem;
                $item->order_id = $order->id;
                $item->product_id = $product->id;
                $item->product_name_snapshot = $product->name;
                $item->sku_snapshot = $product->sku;
                $item->quantity = $quantity;
                $item->unit_price_minor = $unitPriceMinor;
                $item->discount_minor = $lineDiscountMinor;
                $item->line_total_minor = $lineTotalMinor;
                $item->save();
            }

            if ($this->lines !== []) {
                $money = fn (int $minor): Money => Money::fromMinor($minor, $order->currency);
                $delivery = $order->delivery_minor;
                $tax = $order->tax_minor;

                $order->forceFill([
                    'subtotal_minor' => $subtotalMinor,
                    'discount_minor' => $discountMinor,
                    'total_minor' => $subtotalMinor - $discountMinor + $delivery + $tax,
                    'store_wallet_applied_minor' => 0,
                    'payable_minor' => $subtotalMinor - $discountMinor + $delivery + $tax,
                ]);

                $order->pricing_snapshot = (new CheckoutPricing(
                    source: $order->source,
                    subtotal: $money($subtotalMinor),
                    discount: $money($discountMinor),
                    delivery: $money($delivery),
                    tax: $money($tax),
                    total: $money($order->total_minor),
                    discountCredits: $order->discount_credits,
                    storeWalletApplied: Money::fromMinor(0, $order->currency),
                    payable: $money($order->payable_minor),
                ))->toArray();

                $order->save();
            }
        });
    }

    /**
     * Attach specific product lines to the order. Prices are taken from each
     * product unless a price is given; line totals and the order's pricing
     * columns and snapshot are recomputed so the order still adds up.
     *
     * @param  list<array{product: Product, quantity?: int, price_minor?: int}>  $lines
     */
    public function withItems(array $lines): static
    {
        $this->lines = $lines;

        return $this;
    }

    /**
     * Every column is guarded or lifecycle state, so the model has no fillable
     * attributes at all. The factory writes them directly.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = []): Order
    {
        $order = new Order;
        $order->forceFill($attributes);

        return $order;
    }

    /**
     * Not named `for`: the base factory already has one with a different
     * signature, and overriding it would break every relationship helper.
     */
    public function ownedBy(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->id]);
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Paid,
            'paid_at' => Carbon::now(),
        ]);
    }

    /**
     * Priced at a given total, with no discount, delivery or tax.
     */
    public function totalling(int $minor): static
    {
        return $this->state(fn (): array => [
            'subtotal_minor' => $minor,
            'total_minor' => $minor,
            'payable_minor' => $minor,
        ]);
    }

    /**
     * A checkout whose window has already closed.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'payment_due_at' => Carbon::now()->subMinute(),
        ]);
    }
}
