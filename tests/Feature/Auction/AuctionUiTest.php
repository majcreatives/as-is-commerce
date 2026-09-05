<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Actions\CompleteBuyNow;
use App\Domain\Auction\Actions\CreateAuction;
use App\Domain\Auction\Actions\PlaceBid;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Shared\Money\Money;
use App\Enums\AuctionStatus;
use App\Livewire\Admin\Auctions\AuctionDetail;
use App\Livewire\Admin\Auctions\AuctionManager;
use App\Livewire\Auctions\AuctionIndex;
use App\Livewire\Auctions\AuctionRoom;
use App\Models\Auction;
use App\Models\AuctionRuleset;
use App\Models\Bid;
use App\Models\Product;
use Livewire\Livewire;

/*
 * The interface: what it shows, what it refuses, and the words it uses.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    $this->admin = userWithRole('admin');
});

// --------------------------------------------------------- Public listing

it('lists no auctions when none exist', function (): void {
    // An honest empty state rather than examples, and one that gives the
    // customer somewhere to go rather than a dead end.
    $this->get(route('auctions.index'))
        ->assertOk()
        ->assertSee('No auctions open right now')
        ->assertSee('New auctions open regularly')
        ->assertSee('Browse the shop')
        // And it still tells them the rule that matters most before they bid.
        ->assertSee('not returned if you do not win');
});

it('lists an open auction', function (): void {
    $auction = liveAuction(product: Product::factory()->active()->create(['name' => 'Nokia Handset']));

    Livewire::test(AuctionIndex::class)
        ->assertOk()
        ->assertSee('Nokia Handset')
        ->assertSee('Highest Bid (Credits)');
});

it('never lists a draft auction', function (): void {
    Auction::factory()->create();

    Livewire::test(AuctionIndex::class)
        ->assertSee('No auctions open right now')
        // The draft is not merely hidden from the list: it is not there at all.
        ->assertViewHas('auctions', fn ($page): bool => $page->total() === 0);
});

it('keeps a closed auction visible', function (): void {
    $auction = liveAuction(product: Product::factory()->active()->create(['name' => 'Closed Item']));
    app(CloseAuction::class)->handle($auction, force: true);

    Livewire::test(AuctionIndex::class)
        ->set('filter', 'ended')
        ->assertSee('Closed Item');
});

// ------------------------------------------------------------ Auction room

it('404s on a draft auction', function (): void {
    $this->get(route('auctions.show', Auction::factory()->create()))->assertNotFound();
});

it('shows the three figures without conflating them', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product, settlementMinor: 10_000);
    $user = bidder(1_000);
    placeBid($auction, $user, 150);

    Livewire::actingAs($user)
        ->test(AuctionRoom::class, ['auction' => $auction])
        ->assertOk()
        // Credits, as a count.
        ->assertSee('Highest Bid (Credits)')
        ->assertSee('150')
        // The product's Buy Now price and the settlement amount, as money.
        ->assertSee('GH₵ 5,500.00', escape: false)
        ->assertSee('GH₵ 100.00', escape: false)
        // The credits are never written as money.
        ->assertDontSee('GH₵150 credits', escape: false);
});

it('says plainly that credits are not cash', function (): void {
    $auction = liveAuction();

    Livewire::actingAs(bidder())
        ->test(AuctionRoom::class, ['auction' => $auction])
        ->assertSee('Credits are not cash')
        ->assertSee('permanently consumed')
        ->assertSee('not returned if you win');
});

it('never calls the highest bid an auction price', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(), 150);

    Livewire::test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertDontSee('Auction price')
        ->assertDontSee('auction price');
});

it('shows the Buy Now price after the bidder own credit discount', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $user = bidder(1_000);
    placeBid($auction, $user, 150);

    Livewire::actingAs($user)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('GH₵ 5,350.00', escape: false)
        ->assertSee('credit discount');
});

it('does not offer another bidder discount to a different customer', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $spender = bidder(1_000);
    $onlooker = bidder(1_000);

    placeBid($auction, $spender, 150);

    Livewire::actingAs($onlooker)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('GH₵ 5,500.00', escape: false)
        ->assertDontSee('GH₵ 5,350.00', escape: false);
});

/*
 * This asserted the opposite until checkout existed: with no payment path, a
 * Buy Now button would have ended an auction on a payment that never happened.
 * There is a payment path now, and the button opens a checkout -- which still
 * does not end the auction. Only a verified payment does.
 */
it('offers a Buy Now button that opens a checkout without ending the auction', function (): void {
    $auction = liveAuction();

    Livewire::actingAs(bidder())
        ->test(AuctionRoom::class, ['auction' => $auction])
        ->assertSee('Buy now for')
        ->assertSee('does not end this auction');

    expect($auction->fresh()->status->acceptsBids())->toBeTrue();
});

// --------------------------------------------------------------- Bidding

it('places a bid from the auction page', function (): void {
    $auction = liveAuction();
    $user = bidder(1_000);

    Livewire::actingAs($user)
        ->test(AuctionRoom::class, ['auction' => $auction])
        ->set('amount', '150')
        ->call('bid')
        ->assertHasNoErrors();

    expect(Bid::count())->toBe(1)
        ->and(Bid::first()->amount_credits)->toBe(150)
        ->and(creditWalletFor($user)->fresh()->balance)->toBe(850);
});

it('reports the domain reason when a bid is refused', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()
        ->withBidRules(minimum: 100)->create();
    $auction = liveAuction(ruleset: $ruleset);

    Livewire::actingAs(bidder(1_000))
        ->test(AuctionRoom::class, ['auction' => $auction])
        ->set('amount', '20')
        ->call('bid')
        ->assertHasErrors('amount');

    expect(Bid::count())->toBe(0);
});

it('refuses a bid amount that is not a whole number of credits', function (string $amount): void {
    $auction = liveAuction();

    Livewire::actingAs(bidder(1_000))
        ->test(AuctionRoom::class, ['auction' => $auction])
        ->set('amount', $amount)
        ->call('bid')
        ->assertHasErrors('amount');

    expect(Bid::count())->toBe(0);
})->with(['', '12.50', '-5', 'GHS 150', 'abc']);

it('consumes the credits once when the same bid is submitted twice', function (): void {
    $auction = liveAuction();
    $user = bidder(1_000);

    $component = Livewire::actingAs($user)->test(AuctionRoom::class, ['auction' => $auction]);
    $key = $component->get('bidKey');

    // The same intent delivered twice, as a double tap would.
    app(PlaceBid::class)->handle($auction, $user, 150, $key);
    app(PlaceBid::class)->handle($auction->fresh(), $user, 150, $key);

    expect(Bid::count())->toBe(1)
        ->and(creditWalletFor($user)->fresh()->balance)->toBe(850);
});

it('issues a new key after a bid succeeds so the next one is a real bid', function (): void {
    $auction = liveAuction();

    $component = Livewire::actingAs(bidder(1_000))->test(AuctionRoom::class, ['auction' => $auction]);
    $before = $component->get('bidKey');

    $component->set('amount', '100')->call('bid')->assertHasNoErrors();

    expect($component->get('bidKey'))->not->toBe($before);
});

it('does not offer a bid form on a closed auction', function (): void {
    $auction = liveAuction();
    app(CloseAuction::class)->handle($auction, force: true);

    Livewire::actingAs(bidder())
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertDontSee('Commit credits');
});

it('asks a guest to sign in rather than showing a bid form', function (): void {
    Livewire::test(AuctionRoom::class, ['auction' => liveAuction()])
        ->assertSee('to bid on this auction')
        ->assertDontSee('Commit credits');
});

it('explains a Buy Now ending to the bidders who lost', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $bidder = bidder(1_000);
    placeBid($auction, $bidder, 400);

    app(CompleteBuyNow::class)->handle($auction->fresh(), bidder(), Money::fromMinor(550_000), 'buy');

    Livewire::actingAs($bidder)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('bought this product outright')
        // Stage 8 reworded this card to lead with "Sold via Buy Now" and to
        // say outright that there is no auction winner. The behaviour is
        // unchanged; only the sentence is.
        ->assertSee('There is no auction winner')
        ->assertSee('the highest bidder did not win');
});

// ------------------------------------------------------------ Admin index

it('lists auctions for an administrator', function (): void {
    liveAuction(product: Product::factory()->active()->create(['name' => 'Admin Visible']));

    Livewire::actingAs($this->admin)
        ->test(AuctionManager::class)
        ->assertOk()
        ->assertSee('Admin Visible');
});

it('creates a draft auction from the admin form', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $ruleset = AuctionRuleset::factory()->active()->create();

    Livewire::actingAs($this->admin)
        ->test(AuctionManager::class)
        ->call('create')
        ->set('product_id', $product->id)
        ->set('auction_ruleset_id', $ruleset->id)
        ->set('settlement_amount', '100.00')
        ->call('save')
        ->assertHasNoErrors();

    $auction = Auction::firstWhere('product_id', $product->id);

    expect($auction)->not->toBeNull()
        ->and($auction->status)->toBe(AuctionStatus::Draft)
        // Entered as "100.00", stored as whole pesewas.
        ->and($auction->settlement_amount_minor)->toBe(10_000)
        // And nowhere near the product's own price.
        ->and($product->fresh()->buy_now_price_minor)->toBe(550_000);
});

it('converts the settlement amount without a float', function (): void {
    $product = Product::factory()->active()->create();

    Livewire::actingAs($this->admin)
        ->test(AuctionManager::class)
        ->call('create')
        ->set('product_id', $product->id)
        ->set('auction_ruleset_id', AuctionRuleset::factory()->active()->create()->id)
        ->set('settlement_amount', '0.29')
        ->call('save')
        ->assertHasNoErrors();

    expect(Auction::firstWhere('product_id', $product->id)?->settlement_amount_minor)->toBe(29);
});

it('rejects a settlement amount that is not money', function (string $amount): void {
    $product = Product::factory()->active()->create();

    Livewire::actingAs($this->admin)
        ->test(AuctionManager::class)
        ->call('create')
        ->set('product_id', $product->id)
        ->set('auction_ruleset_id', AuctionRuleset::factory()->active()->create()->id)
        ->set('settlement_amount', $amount)
        ->call('save')
        ->assertHasErrors('settlement_amount');

    expect(Auction::count())->toBe(0);
})->with(['', 'GHS 100', '100.005', 'free', '-100']);

it('shows the product Buy Now price beside the settlement field', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();

    Livewire::actingAs($this->admin)
        ->test(AuctionManager::class)
        ->call('create')
        ->set('product_id', $product->id)
        // Both figures on screen at once, so one cannot be typed for the other.
        ->assertSee('GH₵ 5,500.00', escape: false)
        ->assertSee('Auction Settlement Amount');
});

// ----------------------------------------------------------- Admin detail

it('publishes an auction from the detail screen', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $auction = app(CreateAuction::class)->handle(
        $product->fresh(),
        AuctionRuleset::factory()->active()->create(),
        Money::fromMinor(10_000),
    );

    Livewire::actingAs($this->admin)
        ->test(AuctionDetail::class, ['auction' => $auction])
        ->call('start')
        ->assertHasNoErrors();

    expect($auction->fresh()->status)->toBe(AuctionStatus::Live)
        ->and($product->fresh()->stock_reserved)->toBe(1);
});

it('reports a refused publication rather than failing silently', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    liveAuction(product: $product->fresh());

    $second = app(CreateAuction::class)->handle(
        $product->fresh(),
        AuctionRuleset::factory()->active()->create(),
        Money::fromMinor(10_000),
    );

    Livewire::actingAs($this->admin)
        ->test(AuctionDetail::class, ['auction' => $second])
        ->call('start')
        ->assertHasErrors('lifecycle');

    expect($second->fresh()->status)->toBe(AuctionStatus::Draft);
});

it('requires a reason to cancel', function (): void {
    Livewire::actingAs($this->admin)
        ->test(AuctionDetail::class, ['auction' => liveAuction()])
        ->set('cancelReason', '')
        ->call('cancel')
        ->assertHasErrors('cancelReason');
});

it('cancels with a reason that is recorded', function (): void {
    $auction = liveAuction();

    Livewire::actingAs($this->admin)
        ->test(AuctionDetail::class, ['auction' => $auction])
        ->set('cancelReason', 'Product damaged in transit.')
        ->call('cancel')
        ->assertHasNoErrors();

    expect($auction->fresh()->status)->toBe(AuctionStatus::Cancelled)
        ->and($auction->transitions()->latest('id')->first()?->reason)
        ->toBe('Product damaged in transit.');
});

it('shows the frozen snapshot rather than the live ruleset', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()
        ->withBidRules(minimum: 20)->create(['name' => 'Original']);

    $auction = liveAuction(ruleset: $ruleset);

    $ruleset->update(['minimum_bid_credits' => 999]);

    Livewire::actingAs($this->admin)
        ->test(AuctionDetail::class, ['auction' => $auction->fresh()])
        ->assertSee('20 credits')
        ->assertDontSee('999 credits')
        ->assertSee('highest_valid_credit_bid');
});

it('offers no way to edit a live auction rules', function (): void {
    Livewire::actingAs($this->admin)
        ->test(AuctionDetail::class, ['auction' => liveAuction()])
        ->assertSee('Nothing can change it now')
        ->assertDontSee('Edit rules');
});

it('reports a highest-bid projection that disagrees with the bids', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(), 150);

    DB::table('auctions')->where('id', $auction->id)->update(['bid_count' => 99]);

    Livewire::actingAs($this->admin)
        ->test(AuctionDetail::class, ['auction' => $auction->fresh()])
        ->assertSee('does not match the bid records')
        // Reported, not repaired.
        ->assertSee('Report this rather than editing anything');

    expect($auction->fresh()->bid_count)->toBe(99);
});

it('closes an auction early from the detail screen', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    placeBid($auction, $winner, 200);

    Livewire::actingAs($this->admin)
        ->test(AuctionDetail::class, ['auction' => $auction])
        ->call('closeNow')
        ->assertHasNoErrors();

    expect($auction->fresh()->status)->toBe(AuctionStatus::PendingSettlement)
        ->and($auction->fresh()->winner_user_id)->toBe($winner->id);
});

// --------------------------------------------------------- Authorization

it('forbids a customer from the admin auction list', function (): void {
    Livewire::actingAs(userWithRole('customer'))
        ->test(AuctionManager::class)
        ->assertForbidden();
});

it('forbids a customer from the admin auction detail', function (): void {
    Livewire::actingAs(userWithRole('customer'))
        ->test(AuctionDetail::class, ['auction' => liveAuction()])
        ->assertForbidden();
});

it('keeps a customer out of the admin auction routes', function (): void {
    $this->actingAs(userWithRole('customer'))
        ->get(route('admin.auctions.index'))
        ->assertForbidden();
});

/*
 * The lesson from the wallet screens, retested here: an admin screen must not
 * be gated on a permission every customer holds.
 */
it('does not gate the admin auction list on a permission customers hold', function (): void {
    $customer = userWithRole('customer');

    expect($customer->can('bids.place'))->toBeTrue()
        ->and($customer->can('auctions.view'))->toBeFalse()
        ->and($customer->can('bids.inspect'))->toBeFalse();
});

it('lets an administrator reach the auction screens', function (): void {
    $auction = liveAuction();

    $this->actingAs($this->admin)->get(route('admin.auctions.index'))->assertOk();
    $this->actingAs($this->admin)->get(route('admin.auctions.show', $auction))->assertOk();
});
