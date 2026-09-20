<?php

declare(strict_types=1);

use App\Domain\Auction\Services\HighestBidResolver;
use App\Livewire\Auctions\AuctionRoom;
use Livewire\Livewire;

/*
 * How a bid history names the people in it.
 *
 * A number on this screen identifies a PARTICIPANT, not a bid. The screen
 * previously numbered rows by the bid's own `sequence`, so one bidder placing
 * three bids appeared as Bidder #1, #2 and #3 -- three rivals where there was
 * one person, which overstates how contested an auction is to everybody
 * reading it.
 *
 * The other half of the rule is privacy: no bidder is ever named to another
 * bidder. These tests assert both together, because a participant number is
 * only safe while it stays an ordinal.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    $this->bids = app(HighestBidResolver::class);
});

// ------------------------------------------------- Participants, not bids

it('gives one bidder one number however many times they bid', function (): void {
    $auction = liveAuction();
    $persistent = bidder(500);

    placeBid($auction, $persistent, 10);
    placeBid($auction, $persistent, 20);
    placeBid($auction, $persistent, 30);

    $numbers = $this->bids->participantNumbers($auction);

    expect($numbers)->toHaveCount(1)
        ->and($numbers[$persistent->id])->toBe(1);
});

it('numbers participants by when they first bid', function (): void {
    $auction = liveAuction();
    $first = bidder(500);
    $second = bidder(500);
    $third = bidder(500);

    placeBid($auction, $first, 10);
    placeBid($auction, $second, 20);
    placeBid($auction, $third, 30);
    // The first bidder returns. They are still participant 1: the number
    // describes who they are on this auction, not when this bid landed.
    placeBid($auction, $first, 40);

    $numbers = $this->bids->participantNumbers($auction);

    expect($numbers[$first->id])->toBe(1)
        ->and($numbers[$second->id])->toBe(2)
        ->and($numbers[$third->id])->toBe(3);
});

it('does not renumber a participant when the history grows past what is displayed', function (): void {
    // The room shows 25 rows. Numbering within that page would give the
    // earliest bidder a new number as soon as their first bid scrolled off
    // the bottom, so the map is built across the whole auction.
    $auction = liveAuction();
    $earliest = bidder(2_000);

    placeBid($auction, $earliest, 5);

    for ($i = 0; $i < 30; $i++) {
        placeBid($auction, bidder(500), 10 + $i);
    }

    $numbers = $this->bids->participantNumbers($auction);

    expect($numbers[$earliest->id])->toBe(1);
});

it('numbers participants per auction, so the same bidder is not traceable between two', function (): void {
    $regular = bidder(1_000);
    $other = bidder(1_000);

    $first = liveAuction();
    placeBid($first, $other, 10);
    placeBid($first, $regular, 20);

    $second = liveAuction();
    placeBid($second, $regular, 10);

    expect($this->bids->participantNumbers($first)[$regular->id])->toBe(2)
        ->and($this->bids->participantNumbers($second)[$regular->id])->toBe(1);
});

it('has no participants before anybody bids', function (): void {
    expect($this->bids->participantNumbers(liveAuction()))->toBe([]);
});

// ------------------------------------------------------------ On the page

it('shows one repeat bidder as a single participant in the room', function (): void {
    $auction = liveAuction();
    $persistent = bidder(500);
    $viewer = bidder(500);

    placeBid($auction, $persistent, 10);
    placeBid($auction, $persistent, 20);
    placeBid($auction, $persistent, 30);

    Livewire::actingAs($viewer)
        ->test(AuctionRoom::class, ['auction' => $auction])
        ->assertOk()
        ->assertSee('Bidder #1')
        // The old behaviour: three bids from one person numbered as three.
        ->assertDontSee('Bidder #2')
        ->assertDontSee('Bidder #3');
});

it('calls the viewer You and never names another bidder', function (): void {
    $auction = liveAuction();
    $someoneElse = bidder(500);
    $someoneElse->forceFill(['name' => 'Ama Serwaa'])->save();

    $viewer = bidder(500);

    placeBid($auction, $someoneElse, 10);
    placeBid($auction, $viewer, 20);

    Livewire::actingAs($viewer)
        ->test(AuctionRoom::class, ['auction' => $auction])
        ->assertOk()
        ->assertSee('You')
        ->assertSee('Bidder #1')
        // Nothing that could identify the other bidder reaches the page.
        ->assertDontSee('Ama Serwaa')
        ->assertDontSee($someoneElse->phone);
});

it('does not load the bidder for a customer-facing history', function (): void {
    // The identity a customer page must not print is not fetched at all,
    // rather than fetched and then withheld by the template.
    $auction = liveAuction();
    placeBid($auction, bidder(500), 10);

    $history = $this->bids->history($auction, 25);

    expect($history)->toHaveCount(1)
        ->and($history->first()->relationLoaded('user'))->toBeFalse();
});

it('loads the bidder for a staff history that asks for it', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(500), 10);

    $history = $this->bids->history($auction, 100, withBidder: true);

    expect($history->first()->relationLoaded('user'))->toBeTrue();
});
