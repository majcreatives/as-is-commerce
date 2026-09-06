<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Enums\DeliveryStatus;
use App\Livewire\Admin\Auctions\AuctionManager;
use App\Livewire\Admin\Orders\OrderManager;
use Livewire\Livewire;

/*
 * The filters an operator actually reaches for.
 *
 * Both screens already filtered by status. Neither could answer the questions
 * operations asks daily: where is the package, what happened last week, what
 * closes this afternoon, who owes us money and is late. These are those.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();

    $this->admin = userWithRole('admin');
});

// --------------------------------------------------------------- Orders

it('filters orders by where the package is', function (): void {
    $delivery = deliveryAt(DeliveryStatus::Dispatched, staff: $this->admin);
    $other = deliveryAt(DeliveryStatus::Pending, staff: $this->admin);

    Livewire::actingAs($this->admin)
        ->test(OrderManager::class)
        ->set('delivery', DeliveryStatus::Dispatched->value)
        ->assertOk()
        ->assertSee($delivery->order->order_number)
        ->assertDontSee($other->order->order_number);
});

it('separates an order with no delivery from one that has not started', function (): void {
    // Paid, delivery opened, nothing packed yet.
    $waiting = deliveryAt(DeliveryStatus::Pending, staff: $this->admin);

    // An open checkout: no delivery record exists at all.
    $unpaid = buyNowCheckout(bidder(), stockedProduct());

    Livewire::actingAs($this->admin)
        ->test(OrderManager::class)
        ->set('delivery', 'none')
        ->assertOk()
        ->assertSee($unpaid->order_number)
        ->assertDontSee($waiting->order->order_number);
});

it('filters orders by when they were placed', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());

    $placedOn = $order->fresh()->placed_at
        ->timezone(settings()->getString('display_timezone', 'UTC'))
        ->toDateString();

    Livewire::actingAs($this->admin)
        ->test(OrderManager::class)
        ->set('from', $placedOn)
        ->set('to', $placedOn)
        ->assertOk()
        ->assertSee($order->order_number)
        // A day either side of the window excludes it, which is the whole
        // point of the boundary being computed in the display timezone.
        ->set('from', now()->addDays(2)->toDateString())
        ->set('to', now()->addDays(3)->toDateString())
        ->assertDontSee($order->order_number);
});

it('ignores an unparseable date rather than failing the page', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());

    Livewire::actingAs($this->admin)
        ->test(OrderManager::class)
        ->set('from', 'not-a-date')
        ->assertOk()
        ->assertSee($order->order_number);
});

it('clears every order filter at once', function (): void {
    $order = buyNowCheckout(bidder(), stockedProduct());

    Livewire::actingAs($this->admin)
        ->test(OrderManager::class)
        ->set('delivery', DeliveryStatus::Delivered->value)
        ->assertDontSee($order->order_number)
        ->call('clearFilters')
        ->assertSee($order->order_number);
});

// --------------------------------------------------------------- Auctions

it('filters auctions to those closing soon, by the clock and not the status', function (): void {
    $soon = liveAuction();
    $later = liveAuction();

    // The clock is the only authority on when an auction ends. Both are Live.
    $soon->forceFill(['ends_at' => now()->addHour()])->saveQuietly();
    $later->forceFill(['ends_at' => now()->addDays(3)])->saveQuietly();

    Livewire::actingAs($this->admin)
        ->test(AuctionManager::class)
        ->set('endingSoon', true)
        ->assertOk()
        ->assertSee($soon->product->name)
        ->assertDontSee($later->product->name);
});

it('filters auctions to winners who owe, and to those already late', function (): void {
    $auction = liveAuction();
    $winner = bidder();
    placeBid($auction, $winner, 100);

    app(CloseAuction::class)->handle($auction->fresh(), force: true);

    $awaiting = $auction->fresh();

    expect($awaiting->winner_user_id)->toBe($winner->id);

    Livewire::actingAs($this->admin)
        ->test(AuctionManager::class)
        ->set('settlement', 'awaiting')
        ->assertOk()
        ->assertSee($awaiting->product->name)
        // The deadline has not passed, so it is not late.
        ->set('settlement', 'overdue')
        ->assertDontSee($awaiting->product->name);

    $awaiting->forceFill(['settlement_due_at' => now()->subHour()])->saveQuietly();

    Livewire::actingAs($this->admin)
        ->test(AuctionManager::class)
        ->set('settlement', 'overdue')
        ->assertSee($awaiting->product->name);
});

it('clears every auction filter at once', function (): void {
    $auction = liveAuction();

    Livewire::actingAs($this->admin)
        ->test(AuctionManager::class)
        ->set('settlement', 'overdue')
        ->assertDontSee($auction->product->name)
        ->call('clearFilters')
        ->assertSee($auction->product->name);
});
