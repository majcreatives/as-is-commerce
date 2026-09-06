<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Enums\AuctionStatus;
use App\Livewire\Auctions\AuctionRoom;
use App\Models\User;
use Livewire\Livewire;

/*
 * What the polling surface exposes, and to whom.
 *
 * Every poll re-renders the whole component, so anything the template can
 * reach is disclosed on a five-second loop. These tests hold the line the
 * marketplace layer drew: how much was bid is public, who bid it is not, and
 * every action is authorized server-side rather than by whether a button was
 * drawn.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();
});

// -------------------------------------------------------------- Disclosure

it('names no other bidder, however many times the page is polled', function (): void {
    $auction = liveAuction();

    $rival = bidder(1_000);
    $rival->forceFill([
        'name' => 'Kwame Asante',
        'email' => 'kwame@example.test',
        'phone' => '+233241112222',
    ])->saveQuietly();

    placeBid($auction->fresh(), $rival, 250);

    $viewer = bidder(1_000);
    placeBid($auction->fresh(), $viewer, 100);

    $html = Livewire::actingAs($viewer)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->call('$refresh')
        ->html();

    // The amount is public. The person is not.
    expect($html)->toContain('250')
        ->and($html)->not->toContain('Kwame Asante')
        ->and($html)->not->toContain('kwame@example.test')
        ->and($html)->not->toContain('+233241112222');
});

it('shows a signed-out visitor no wallet, no credits and no private state', function (): void {
    $auction = liveAuction();
    $bidder = bidder(1_000);
    placeBid($auction->fresh(), $bidder, 300);

    $html = Livewire::test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->call('$refresh')
        ->html();

    expect($html)->toContain('300')
        ->and($html)->not->toContain('Your credit balance')
        ->and($html)->not->toContain('already committed');
});

it('renders no authentication or payment material', function (): void {
    $auction = liveAuction();
    $viewer = bidder(1_000);
    placeBid($auction->fresh(), $viewer, 100);

    $html = Livewire::actingAs($viewer)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->call('$refresh')
        ->html();

    foreach ([$viewer->getAuthPassword(), 'remember_token', 'authorization_code', 'sk_test', 'sk_live'] as $secret) {
        expect($html)->not->toContain((string) $secret);
    }
});

it('does not expose another customer on the auction it belongs to', function (): void {
    $auction = liveAuction();
    $winner = bidder(1_000);
    placeBid($auction->fresh(), $winner, 400);

    app(CloseAuction::class)->handle($auction->fresh(), force: true);

    // A different customer looking at a closed auction they did not win must
    // not learn who did, nor see the winner's settlement checkout.
    $stranger = bidder(1_000);

    $html = Livewire::actingAs($stranger)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->call('$refresh')
        ->html();

    expect($html)->not->toContain($winner->name)
        ->and($html)->not->toContain((string) $winner->phone);
});

// ------------------------------------------------------------ Authorization

it('refuses a bid from somebody without the permission', function (): void {
    $auction = liveAuction();

    // Signed in, holding no role at all. The action authorizes rather than
    // trusting that the form was never drawn for them.
    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->set('amount', '100')
        ->call('bid')
        ->assertForbidden();
});

it('refuses a checkout from somebody without the permission', function (): void {
    $auction = liveAuction();
    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->call('buyNow')
        ->assertForbidden();

    Livewire::actingAs($outsider)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->call('settle')
        ->assertForbidden();
});

it('validates the bid amount server-side rather than trusting the field', function (): void {
    $auction = liveAuction();
    $viewer = bidder(1_000);

    foreach (['-5', '10.5', 'abc', '', '1e9'] as $rubbish) {
        Livewire::actingAs($viewer)
            ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
            ->set('amount', $rubbish)
            ->call('bid')
            ->assertHasErrors('amount');
    }

    // Nothing was consumed by any of it.
    expect(creditWalletFor($viewer)->fresh()->balance)->toBe(1_000);
});

it('hides an auction that is not publicly visible', function (): void {
    $auction = liveAuction();
    $auction->forceFill(['status' => AuctionStatus::Draft])->saveQuietly();

    Livewire::actingAs(bidder())
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertStatus(404);
});
