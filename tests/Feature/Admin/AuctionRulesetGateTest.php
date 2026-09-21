<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Livewire\Admin\Auctions\AuctionDetail;
use App\Livewire\Admin\Auctions\AuctionManager;
use App\Models\Auction;
use App\Models\AuctionRuleset;
use App\Models\Product;
use Livewire\Livewire;

/*
 * What an administrator is OFFERED when starting an auction.
 *
 * Rulesets made under the earlier rule still exist, still explain every auction
 * already made from them, and the domain still honours them -- but nothing in
 * the product offers one for a NEW auction, so the earlier rule cannot be
 * started afresh. Being offered is a different question from being allowed by
 * the domain, and it is asked of the server as well as the list: an id in a
 * request is not a capability.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    $this->admin = userWithRole('admin');
});

it('offers only rulesets that use the current bidding rules', function (): void {
    AuctionRuleset::factory()->active()->create(['name' => 'Older Rule Set']);
    AuctionRuleset::factory()->active()->cumulative()->create(['name' => 'Current Rule Set']);

    Livewire::actingAs($this->admin)
        ->test(AuctionManager::class)
        ->call('create')
        ->assertSee('Current Rule Set')
        ->assertDontSee('Older Rule Set');
});

it('selects a current ruleset by default, never an older one', function (): void {
    AuctionRuleset::factory()->default()->create(['name' => 'Older Default']);
    $current = AuctionRuleset::factory()->active()->cumulative()->create(['name' => 'Current One']);

    Livewire::actingAs($this->admin)
        ->test(AuctionManager::class)
        ->call('create')
        ->assertSet('auction_ruleset_id', $current->id);
});

it('refuses a forged ruleset id that names an older ruleset', function (): void {
    $product = Product::factory()->active()->create();
    $older = AuctionRuleset::factory()->active()->create();

    Livewire::actingAs($this->admin)
        ->test(AuctionManager::class)
        ->call('create')
        ->set('product_id', $product->id)
        ->set('auction_ruleset_id', $older->id)
        ->set('settlement_amount', '100.00')
        ->call('save')
        ->assertHasErrors('auction_ruleset_id');

    expect(Auction::query()->where('product_id', $product->id)->exists())->toBeFalse();
});

it('says what to do when there is no current ruleset to start an auction from', function (): void {
    AuctionRuleset::factory()->active()->create();

    Livewire::actingAs($this->admin)
        ->test(AuctionManager::class)
        ->call('create')
        ->assertSee('No active ruleset uses the current bidding rules')
        ->assertSee('Draft a new version');
});

it('offers only current rulesets when relisting, and refuses a forged one', function (): void {
    $older = AuctionRuleset::factory()->active()->create(['name' => 'Older For Relist']);
    $current = AuctionRuleset::factory()->active()->cumulative()->create(['name' => 'Current For Relist']);

    // An auction that closed without selling: the only kind that may be relisted.
    $auction = liveAuction(ruleset: AuctionRuleset::factory()->active()->withoutThrottle()->create());
    app(CloseAuction::class)->handle($auction, force: true);

    $component = Livewire::actingAs($this->admin)
        ->test(AuctionDetail::class, ['auction' => $auction->fresh()])
        ->assertSee('Current For Relist')
        ->assertDontSee('Older For Relist');

    $component->set('relistRulesetId', $older->id)
        ->call('relist')
        ->assertHasErrors('relistRulesetId');

    expect(Auction::count())->toBe(1);

    $component->set('relistRulesetId', $current->id)
        ->call('relist')
        ->assertHasNoErrors();

    expect(Auction::count())->toBe(2)
        ->and(Auction::query()->latest('id')->first()->rules()->bidModel->isCumulative())->toBeTrue();
});

it('still explains an auction made under the earlier rule', function (): void {
    // Not offering the earlier rule for NEW auctions must not stop anybody
    // reading one that already exists.
    $auction = liveAuction();

    Livewire::actingAs($this->admin)
        ->test(AuctionDetail::class, ['auction' => $auction])
        ->assertOk()
        ->assertSee('Single highest bid')
        ->assertSee('Minimum increment (earlier rule)')
        ->assertSee('Highest Bid (Credits)');
});

it('shows a current auction\'s opening bid, step and totals to staff', function (): void {
    $auction = cumulativeAuction(minimum: 5, increment: 2);
    catchUp($auction, bidder(500));
    catchUp($auction, bidder(500));

    Livewire::actingAs($this->admin)
        ->test(AuctionDetail::class, ['auction' => $auction->fresh()])
        ->assertSee('Cumulative step')
        ->assertSee('highest_cumulative_credits')
        ->assertSee('Opening bid')
        ->assertSee('Bid increment')
        ->assertSee('Highest Total (Credits)')
        ->assertSee('Credits added')
        ->assertSee('Total after');
});
