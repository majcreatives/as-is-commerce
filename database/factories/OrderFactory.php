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
     * Build the pricing snapshot from whatever the amounts ended up as, and
     * attach the single line every order in this stage has.
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

            $product = Product::factory()->active()
                ->pricedAt($order->subtotal_minor)
                ->create();

            $item = new OrderItem;
            $item->order_id = $order->id;
            $item->product_id = $product->id;
            $item->product_name_snapshot = $product->name;
            $item->sku_snapshot = $product->sku;
            $item->quantity = 1;
            $item->unit_price_minor = $order->subtotal_minor;
            $item->discount_minor = $order->discount_minor;
            $item->line_total_minor = $order->subtotal_minor - $order->discount_minor;
            $item->save();
        });
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
