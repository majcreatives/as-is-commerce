<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Actions\ForfeitAuction;
use App\Enums\AuctionStatus;
use App\Enums\OrderStatus;
use App\Livewire\Admin\Auctions\AuctionDetail;
use App\Livewire\Auctions\AuctionRoom;
use App\Models\Product;
use Livewire\Livewire;

/*
 * What the interface says once an auction has ended -- to the winner, to the
 * people who lost, and to staff.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    $this->admin = userWithRole('admin');
    $this->close = app(CloseAuction::class);
});

// -------------------------------------------------------------- The winner

it('sends the winner to a checkout that already exists', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product, settlementMinor: 10_000);
    $winner = bidder(500);
    placeBid($auction, $winner, 180);

    $closed = $this->close->handle($auction, force: true);

    Livewire::actingAs($winner)
        ->test(AuctionRoom::class, ['auction' => $closed])
        ->assertSee('You won this auction')
        ->assertSee('Go to settlement checkout')
        // The obligation, stated as the settlement amount.
        ->assertSee('GH₵ 100.00', escape: false)
        ->assertSee('not your bid converted into GH', escape: false)
        // The bid itself stays a count of credits wherever it is shown.
        ->assertSee('180 credits')
        ->assertDontSee('180 GH', escape: false);

    // GH₵180 does legitimately appear elsewhere on the page -- as the Buy Now
    // discount those same 180 consumed credits would earn. That is the one
    // sanctioned conversion, and it is a different figure from what the winner
    // settles, which is why the two are asserted apart rather than together.
    expect($closed->settlement_amount_minor)->toBe(10_000)
        ->and($closed->settlement_amount_minor)->not->toBe(18_000);
});

it('tells the winner when they must settle by', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    placeBid($auction, $winner, 100);

    Livewire::actingAs($winner)
        ->test(AuctionRoom::class, ['auction' => $this->close->handle($auction, force: true)])
        ->assertSee('Settle by')
        ->assertSee('consumed credits are not returned');
});

it('shows a settled auction as settled rather than payable', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    placeBid($auction, $winner, 100);

    $closed = $this->close->handle($auction, force: true);
    payOrder($closed->settlementOrder);

    Livewire::actingAs($winner)
        ->test(AuctionRoom::class, ['auction' => $closed->fresh()])
        ->assertSee('Settled')
        ->assertDontSee('Go to settlement checkout');
});

// --------------------------------------------------------------- The losers

it('tells a losing bidder plainly that they did not win', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    $loser = bidder(500);

    placeBid($auction, $loser, 80);
    placeBid($auction, $winner, 200);

    $closed = $this->close->handle($auction, force: true);

    Livewire::actingAs($loser)
        ->test(AuctionRoom::class, ['auction' => $closed])
        ->assertSee('You did not win this auction')
        ->assertSee('80')
        ->assertSee('remain consumed');
});

it('offers a losing bidder no refund of any kind', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    $loser = bidder(500);

    placeBid($auction, $loser, 80);
    placeBid($auction, $winner, 200);

    Livewire::actingAs($loser)
        ->test(AuctionRoom::class, ['auction' => $this->close->handle($auction, force: true)])
        ->assertDontSee('Refund')
        ->assertDontSee('refunded')
        ->assertDontSee('returned to your wallet');

    // And nothing came back.
    expect(creditWalletFor($loser)->fresh()->balance)->toBe(420);
});

it('says nothing about losing to somebody who never bid', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    placeBid($auction, $winner, 200);

    Livewire::actingAs(bidder())
        ->test(AuctionRoom::class, ['auction' => $this->close->handle($auction, force: true)])
        ->assertDontSee('You did not win this auction');
});

// ------------------------------------------------------ Buy Now termination

it('shows an auction bought outright as sold via Buy Now', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $leader = bidder(2_000);
    placeBid($auction, $leader, 900);

    payOrder(buyNowCheckout(bidder(), $product->fresh(), $auction->fresh()));

    Livewire::actingAs($leader)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('Sold via Buy Now')
        ->assertSee('There is no auction winner')
        // The leading bidder is told what happened to their credits.
        ->assertSee('remain consumed')
        ->assertDontSee('You won this auction');
});

// ------------------------------------------------------------- Credits

it('shows a bidder their credit balance on the auction page', function (): void {
    $auction = liveAuction();
    $user = bidder(1_234);

    Livewire::actingAs($user)
        ->test(AuctionRoom::class, ['auction' => $auction])
        ->assertSee('Your credits')
        ->assertSee('1,234')
        ->assertSee('never converted to GH', escape: false);
});

// ---------------------------------------------------------------- Admin

it('shows staff the settlement order an auction produced', function (): void {
    $auction = liveAuction(settlementMinor: 10_000);
    $winner = bidder(500);
    placeBid($auction, $winner, 180);

    $closed = $this->close->handle($auction, force: true);

    Livewire::actingAs($this->admin)
        ->test(AuctionDetail::class, ['auction' => $closed])
        ->assertOk()
        ->assertSee('Orders from this auction')
        ->assertSee($closed->settlementOrder->order_number)
        ->assertSee('Auction win');
});

it('flags a Buy Now order that was paid but could not be fulfilled', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $slow = buyNowCheckout(bidder(), $product->fresh(), $auction->fresh());
    payOrder(buyNowCheckout(bidder(), $product->fresh(), $auction->fresh()));
    payOrder($slow);

    Livewire::actingAs($this->admin)
        ->test(AuctionDetail::class, ['auction' => $auction->fresh()])
        ->assertSee('fulfilment blocked')
        ->assertSee('another transaction acquired the unit first')
        ->assertSee('No refund is issued');
});

it('warns staff when a winner has no settlement checkout', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    placeBid($auction, $winner, 100);

    $closed = $this->close->handle($auction, force: true);

    // Remove the order the way only a fault could, to prove the screen
    // surfaces the gap rather than showing a silently empty panel.
    $closed->settlementOrder->transitions()->delete();
    $closed->settlementOrder->items()->delete();
    $closed->settlementOrder->delete();

    Livewire::actingAs($this->admin)
        ->test(AuctionDetail::class, ['auction' => $closed->fresh()])
        ->assertSee('no settlement checkout was opened')
        ->assertSee('cannot pay until one exists');
});

it('cancels an auction and its settlement checkout from the admin screen', function (): void {
    $product = Product::factory()->active()->create();
    $auction = liveAuction(product: $product);
    $winner = bidder(500);
    placeBid($auction, $winner, 100);

    $closed = $this->close->handle($auction, force: true);
    $order = $closed->settlementOrder;

    Livewire::actingAs($this->admin)
        ->test(AuctionDetail::class, ['auction' => $closed])
        ->set('cancelReason', 'Product damaged in the warehouse.')
        ->call('cancel')
        ->assertHasNoErrors();

    expect($auction->fresh()->status)->toBe(AuctionStatus::Cancelled)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($product->fresh()->availableStock())->toBe(1);
});

it('still refuses to let an administrator edit a live auction', function (): void {
    Livewire::actingAs($this->admin)
        ->test(AuctionDetail::class, ['auction' => liveAuction()])
        ->assertSee('Nothing can change it now')
        ->assertDontSee('Edit rules')
        ->assertDontSee('Mark as paid');
});

// ------------------------------------------------------------- Security

it('refuses a settlement checkout to anyone but the winner', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    $other = bidder(500);
    placeBid($auction, $winner, 200);
    placeBid($auction, $other, 50);

    $closed = $this->close->handle($auction, force: true);

    Livewire::actingAs($other)
        ->test(AuctionRoom::class, ['auction' => $closed])
        ->call('settle')
        ->assertHasErrors('checkout');
});

it('does not show one bidder another bidder settlement checkout', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    $other = bidder(500);
    placeBid($auction, $winner, 200);
    placeBid($auction, $other, 50);

    $closed = $this->close->handle($auction, force: true);

    Livewire::actingAs($other)
        ->test(AuctionRoom::class, ['auction' => $closed])
        ->assertDontSee('Go to settlement checkout');
});

it('keeps a customer out of the auction admin screen', function (): void {
    Livewire::actingAs(userWithRole('customer'))
        ->test(AuctionDetail::class, ['auction' => liveAuction()])
        ->assertForbidden();
});

it('refuses a forfeit sweep from doing anything to a live auction', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(500), 100);

    app(ForfeitAuction::class)->handle($auction->fresh());

    // Not PendingSettlement, so nothing happens. A sweep must never forfeit an
    // auction that is still running.
    expect($auction->fresh()->status)->toBe(AuctionStatus::Live);
});
