<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Credit\ValueObjects\CreditAmount;
use App\Enums\NotificationType;
use App\Livewire\Account\Dashboard;
use App\Livewire\Auctions\AuctionIndex;
use App\Livewire\Auctions\AuctionRoom;
use App\Livewire\Catalog\ProductDetail;
use App\Livewire\Checkout\CheckoutPage;
use App\Models\Bid;
use App\Models\Product;
use Livewire\Livewire;

/*
 * What a customer is shown and told under the cumulative model.
 *
 * Three things are held here. The words are the auction's own -- the figure
 * that leads is a TOTAL, never "the highest bid", because a bid is now only the
 * gap it closes -- and an auction made under the earlier rule keeps the words
 * that were true of it. The room never accepts a typed amount. And nothing
 * names another bidder.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);
});

// ---------------------------------------------------------- The words

it('calls the leading figure a total, and states the rule that way', function (): void {
    $auction = cumulativeAuction(minimum: 100, increment: 10);
    placeBid($auction, bidder(1_000), 100);

    Livewire::test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('Highest Total (Credits)')
        ->assertSee('The largest total of credits committed wins')
        ->assertDontSee('Highest Bid (Credits)')
        ->assertDontSee('The highest valid credit bid wins')
        // Never a price, and never money.
        ->assertDontSee('auction price', false)
        ->assertDontSee('GH₵100 credits', false);
});

it('keeps an earlier auction\'s own words, because they were true of it', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(1_000), 100);

    Livewire::test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('Highest Bid (Credits)')
        ->assertSee('The highest valid credit bid wins')
        ->assertDontSee('Highest Total (Credits)');
});

it('shows the leader\'s total, not the size of their last bid', function (): void {
    // Ruleset and wallets scaled by the redenomination factor together, so
    // the displayed (bare) total below is still exactly "3" -- the engine's
    // catch-up arithmetic is scale-invariant, and CreditAmount's bare display
    // divides the same factor back out.
    $factor = CreditAmount::SUBCREDITS_PER_CREDIT;
    $auction = cumulativeAuction($factor, $factor);
    $a = bidder(500 * $factor);
    $b = bidder(500 * $factor);

    catchUp($auction, $a);   // A = 1
    catchUp($auction, $b);   // B = 2
    catchUp($auction, $a);   // A adds 2 -> 3

    // A's last bid was 2. The figure to beat is 3.
    Livewire::test(AuctionRoom::class, ['auction' => $auction->fresh()])
        // Blade's component attribute bag renders a valueless attribute as
        // attr="attr" -- harmless for the JS presence selector that reads it,
        // but the exact string this checks.
        ->assertSeeHtml('<span data-auction-highest-credits="data-auction-highest-credits">3</span>');
});

it('labels the same figure the same way on every surface that shows it', function (): void {
    $product = Product::factory()->active()->create(['name' => 'Nokia Handset']);
    $auction = cumulativeAuction(minimum: 100, increment: 10, product: $product);
    $bidder = bidder(1_000);
    placeBid($auction, $bidder, 100);

    Livewire::test(AuctionIndex::class)->assertSee('Highest Total (Credits)');

    Livewire::test(ProductDetail::class, ['slug' => $product->slug])
        ->assertSee('Highest Total (Credits)')
        ->assertDontSee('Highest Bid (Credits)');

    Livewire::actingAs($bidder)->test(Dashboard::class)->assertSee('Highest Total (Credits)');
});

// ------------------------------------------------------ Bidding, as a bidder

it('takes the owner\'s example through the room, one confirmed bid at a time', function (): void {
    // Ruleset and wallets scaled together, so the raw engine amounts move
    // with the factor while the DISPLAYED figure -- what $bid() asserts --
    // stays the owner's original human numbers throughout.
    $factor = CreditAmount::SUBCREDITS_PER_CREDIT;
    $auction = cumulativeAuction($factor, $factor);
    $a = bidder(500 * $factor);
    $b = bidder(500 * $factor);
    $c = bidder(500 * $factor);

    $bid = function ($user, int $humanShown) use ($auction, $factor): void {
        Livewire::actingAs($user)
            ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
            // assertSeeText: the figure renders inside its own <x-credits> span.
            ->assertSeeText('Bid '.$humanShown.' '.($humanShown === 1 ? 'credit' : 'credits'))
            ->call('review', $humanShown * $factor)
            ->call('bid')
            ->assertHasNoErrors();
    };

    $bid($a, 1);
    $bid($b, 2);
    $bid($a, 2);
    $bid($c, 4);
    $bid($b, 3);

    expect(Bid::query()->orderBy('sequence')->pluck('amount_credits')->all())
        ->toBe(array_map(fn (int $n): int => $n * $factor, [1, 2, 2, 4, 3]))
        ->and(Bid::query()->orderBy('sequence')->pluck('cumulative_credits')->all())
        ->toBe(array_map(fn (int $n): int => $n * $factor, [1, 2, 3, 4, 5]));
});

it('shows the confirmation the figures the bidder needs to decide', function (): void {
    $auction = cumulativeAuction();
    $me = bidder(500);

    catchUp($auction, bidder(500));   // the leader has 1
    catchUp($auction, $me);           // I have 2

    // Somebody takes the lead again, so I have something to decide.
    catchUp($auction, bidder(500));   // 3

    Livewire::actingAs($me)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('To take the lead, add')
        ->call('review', 2)
        ->assertSee('Current highest total')
        ->assertSee('Your total now')
        ->assertSee('Your total after this bid')
        ->assertSee('Your balance afterwards');
});

it('tells a stale page so, with the amount that is right now', function (): void {
    $factor = CreditAmount::SUBCREDITS_PER_CREDIT;
    $auction = cumulativeAuction($factor, $factor);
    $me = bidder(500 * $factor);

    catchUp($auction, bidder(500 * $factor));

    $component = Livewire::actingAs($me)->test(AuctionRoom::class, ['auction' => $auction->fresh()]);

    // Somebody bids after the page was drawn: what I was shown is out of date.
    catchUp($auction, bidder(500 * $factor));

    $component->call('review', 2 * $factor)
        ->assertHasErrors('bid')
        ->assertSet('confirming', false);

    expect($component->errors()->first('bid'))->toContain('you now need to add 3 credits');

    expect(Bid::count())->toBe(2)
        ->and(creditWalletFor($me)->fresh()->balance)->toBe(500 * $factor);
});

it('offers the leader nothing to press, and refuses if they press it anyway', function (): void {
    $auction = cumulativeAuction();
    $leader = bidder(500);

    catchUp($auction, $leader);

    Livewire::actingAs($leader)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('You hold the lead')
        ->assertDontSee('To take the lead, add')
        ->call('review', 2)
        ->assertHasErrors('bid')
        ->assertSet('confirming', false);

    expect(Bid::count())->toBe(1);
});

it('shows a visitor what joining costs, but nothing to press', function (): void {
    $factor = CreditAmount::SUBCREDITS_PER_CREDIT;
    $auction = cumulativeAuction($factor, $factor);

    catchUp($auction, bidder(500 * $factor));
    catchUp($auction, bidder(500 * $factor));

    Livewire::test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('To take the lead right now, a new bidder adds')
        ->assertSee('3 credits')
        ->assertSee('Sign in')
        ->assertDontSee('Bid 3 credits');
});

it('shows a bidder who cannot afford it what they are short, and links to buy credits', function (): void {
    $factor = CreditAmount::SUBCREDITS_PER_CREDIT;
    $auction = cumulativeAuction(minimum: 200 * $factor, increment: 10 * $factor);
    $poor = bidder(150 * $factor);

    Livewire::actingAs($poor)
        ->test(AuctionRoom::class, ['auction' => $auction])
        ->assertSee('You need')
        ->assertSee('50 credits')
        ->assertSee(route('credits.packages'), false)
        ->assertDontSee('Confirm bid');
});

// ------------------------------------------ An auction under the earlier rule

it('shows an auction under the earlier rule, and does not bid on it through this page', function (): void {
    $auction = liveAuction();
    $bidder = bidder(500);

    $component = Livewire::actingAs($bidder)
        ->test(AuctionRoom::class, ['auction' => $auction]);

    $component->assertSee('earlier bidding rules')
        ->assertDontSee('Place a bid')
        ->assertDontSee('Confirm bid');

    // And the action itself refuses, not merely the missing button.
    $component->call('review', 100)->assertHasErrors('bid')->assertSet('confirming', false);

    expect(Bid::count())->toBe(0)
        ->and(creditWalletFor($bidder)->fresh()->balance)->toBe(500);
});

// ---------------------------------------------------------------- History

it('shows what each bid committed, by participant, without the per-bid increment', function (): void {
    // Scaled together so the displayed (bare) totals below still read that way.
    $factor = CreditAmount::SUBCREDITS_PER_CREDIT;
    $auction = cumulativeAuction($factor, $factor);
    $a = bidder(500 * $factor);
    $b = bidder(500 * $factor);
    $b->update(['name' => 'Kwame Mensah']);
    $viewer = bidder(500 * $factor);

    catchUp($auction, $a);   // A: +1 -> 1
    catchUp($auction, $b);   // B: +2 -> 2
    catchUp($auction, $a);   // A: +2 -> 3

    Livewire::actingAs($viewer)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('Credits committed')
        // The per-bid increment column is gone: no "+", no two-column pair.
        ->assertDontSee('Credits added')
        ->assertDontSee('Total after')
        // assertSeeText: the figure renders inside its own <x-credits> span,
        // so "+2" is not a contiguous raw-HTML substring -- and would not
        // appear at all any more.
        ->assertDontSeeText('+2')
        // One person, one number, however many times they bid.
        ->assertSee('Bidder #1')
        ->assertSee('Bidder #2')
        ->assertDontSee('Bidder #3')
        // Never a name or a number.
        ->assertDontSee('Kwame')
        ->assertDontSee($b->phone);
});

it('keeps the earlier auction\'s history in its own terms', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(500), 100);

    Livewire::test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('Bid (credits)')
        ->assertDontSee('Credits added')
        ->assertDontSee('Total after');
});

// ----------------------------------------------------------- The result

it('states the result as a total, to the winner and to the losers', function (): void {
    // Scaled together so the sentences below -- built by BidModel's formatted,
    // customer-facing methods -- still read the owner's original numbers.
    $factor = CreditAmount::SUBCREDITS_PER_CREDIT;
    $auction = cumulativeAuction($factor, $factor);
    $a = bidder(500 * $factor);
    $b = bidder(500 * $factor);
    $c = bidder(500 * $factor);

    catchUp($auction, $a);
    catchUp($auction, $b);
    catchUp($auction, $a);
    catchUp($auction, $c);
    catchUp($auction, $b);   // B leads with 5

    $closed = app(CloseAuction::class)->handle($auction->fresh(), force: true);

    Livewire::actingAs($b)
        ->test(AuctionRoom::class, ['auction' => $closed->fresh()])
        ->assertSee('Won with the highest total: 5 credits committed.')
        ->assertSee('You won this auction');

    Livewire::actingAs($c)
        ->test(AuctionRoom::class, ['auction' => $closed->fresh()])
        ->assertSee('You did not win this auction')
        ->assertSee('The winning total was')
        ->assertDontSee('The winning bid was')
        // A loser's credits are not returned, and the page does not offer them.
        ->assertSee('remain consumed');
});

it('describes the win on the winner\'s checkout and order in the same terms', function (): void {
    $factor = CreditAmount::SUBCREDITS_PER_CREDIT;
    $auction = cumulativeAuction($factor, $factor);
    $a = bidder(500 * $factor);
    $b = bidder(500 * $factor);

    catchUp($auction, $a);
    catchUp($auction, $b);
    catchUp($auction, $a);    // A leads with 3, having bid 1 then 2

    $closed = app(CloseAuction::class)->handle($auction->fresh(), force: true);
    $order = $closed->settlementOrder;

    Livewire::actingAs($a)
        ->test(CheckoutPage::class, ['order' => $order])
        ->assertSee('You won this auction with the highest total: 3 credits committed.')
        ->assertDontSee('highest valid credit bid');

    $this->actingAs($a)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee('You held the highest total, 3 credits committed, when the auction closed.');
});

// -------------------------------------------------------- Notifications

it('tells a bidder where their bid left them', function (): void {
    $auction = cumulativeAuction(
        minimum: 100 * CreditAmount::SUBCREDITS_PER_CREDIT,
        increment: 10 * CreditAmount::SUBCREDITS_PER_CREDIT,
    );
    $me = bidder(1_000 * CreditAmount::SUBCREDITS_PER_CREDIT);

    placeBid($auction, $me, 100 * CreditAmount::SUBCREDITS_PER_CREDIT);

    $message = notificationsFor($me, NotificationType::BidPlaced)->first()->message;

    expect($message)->toContain('Your bid of 100 credits')
        ->and($message)->toContain('You now lead with 100 credits')
        ->and($message)->toContain('not refunded');
});

it('tells the person overtaken that somebody took the lead, not that they bid higher', function (): void {
    $auction = cumulativeAuction(
        minimum: 100 * CreditAmount::SUBCREDITS_PER_CREDIT,
        increment: 10 * CreditAmount::SUBCREDITS_PER_CREDIT,
    );
    $me = bidder(1_000 * CreditAmount::SUBCREDITS_PER_CREDIT);

    placeBid($auction, $me, 100 * CreditAmount::SUBCREDITS_PER_CREDIT);
    placeBid($auction->fresh(), bidder(1_000 * CreditAmount::SUBCREDITS_PER_CREDIT), 110 * CreditAmount::SUBCREDITS_PER_CREDIT);

    $message = notificationsFor($me, NotificationType::Outbid)->first()->message;

    expect($message)->toContain('taken the lead on')
        ->and($message)->toContain('The highest total is now 110 credits')
        ->and($message)->not->toContain('bid higher')
        // It never offers anything back.
        ->and($message)->toContain('stay consumed');
});

it('tells a loser and a winner the figure that decided it', function (): void {
    $auction = cumulativeAuction(
        minimum: CreditAmount::SUBCREDITS_PER_CREDIT,
        increment: CreditAmount::SUBCREDITS_PER_CREDIT,
    );
    $a = bidder(500 * CreditAmount::SUBCREDITS_PER_CREDIT);
    $b = bidder(500 * CreditAmount::SUBCREDITS_PER_CREDIT);

    catchUp($auction, $a);
    catchUp($auction, $b);
    catchUp($auction, $a);   // A = 3 credits

    app(CloseAuction::class)->handle($auction->fresh(), force: true);

    $won = notificationsFor($a, NotificationType::AuctionWon)->first()->message;
    $lost = notificationsFor($b, NotificationType::AuctionLost)->first()->message;

    expect($won)->toContain('Your winning total was 3 credits')
        ->and($lost)->toContain('The winning total was 3 credits')
        ->and($lost)->toContain('not refunded');
});
