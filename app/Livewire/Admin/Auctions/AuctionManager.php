<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Auctions;

use App\Domain\Auction\Actions\CreateAuction;
use App\Domain\Credit\ValueObjects\CreditAmount;
use App\Domain\Shared\Money\Money;
use App\Enums\AuctionStatus;
use App\Enums\BidModel;
use App\Enums\ProductStatus;
use App\Models\Auction;
use App\Models\AuctionRuleset;
use App\Models\Product;
use Closure;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Auction administration: the list, and creating one.
 *
 * THE FORM MAKES THE THREE NUMBERS UNMISTAKABLE. Choosing a product shows its
 * Buy Now price, read-only, right beside the settlement amount field. They are
 * different figures with different meanings, and an administrator typing one
 * should be able to see that they are not typing the other.
 *
 * The settlement amount is entered in cedis and converted to integer pesewas
 * on save, so the form never holds a float. It is a per-auction decision: two
 * auctions on the same product may deliberately settle at GH₵50 and GH₵150,
 * and the interface is built around that rather than treating it as an
 * exception.
 *
 * NO MARGIN PROTECTION. Nothing here compares the settlement amount to the
 * product's price, warns that it is low, or nudges it upwards. Low settlement
 * amounts are the point of the model, not a mistake to guard against.
 *
 * Only drafts can be created here. Publishing, cancelling and everything else
 * about a live auction happens on its own screen, where the consequences are
 * visible.
 */
#[Layout('components.layouts.app')]
#[Title('Auctions')]
class AuctionManager extends Component
{
    use WithPagination;

    /**
     * How close to its end an auction has to be to count as ending soon.
     *
     * A display threshold for a filter, not a business rule: nothing about
     * how an auction runs or who wins depends on it.
     */
    public const ENDING_SOON_HOURS = 6;

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    /**
     * Live auctions closing within the configured window.
     *
     * The operational question a status filter cannot answer: Live says an
     * auction is running, not that somebody should be watching it. The clock
     * is the only authority on when one ends.
     */
    #[Url]
    public bool $endingSoon = false;

    /**
     * Auctions with a winner who has not paid yet.
     *
     * Separate from status because the operationally interesting subset is
     * narrower than PendingSettlement: an overdue deadline is the one worth
     * looking at.
     */
    #[Url]
    public string $settlement = '';

    public bool $showForm = false;

    // Form
    public ?int $product_id = null;

    public ?int $auction_ruleset_id = null;

    public string $settlement_amount = '';

    /**
     * Optional (docs/PLAN_POT_TARGET_BIDDING.md, step 6). Blank means this
     * auction has no second way to close -- the clock alone decides, exactly
     * as every auction does today. Held as a string, entered and displayed in
     * Credits, converted to subcredits only on save -- the same pattern
     * RulesetForm already uses for its own credit fields, so a figure finer
     * than a whole credit is never silently truncated.
     */
    public string $pot_target_credits = '';

    public function mount(): void
    {
        $this->authorize('auctions.view');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedEndingSoon(): void
    {
        $this->resetPage();
    }

    public function updatedSettlement(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'search', 'endingSoon', 'settlement');
        $this->resetPage();
    }

    public function create(): void
    {
        $this->authorize('auctions.create');

        $this->reset('product_id', 'auction_ruleset_id', 'settlement_amount', 'pot_target_credits');

        $this->auction_ruleset_id = AuctionRuleset::query()->active()->acceptingNewAuctions()
            ->orderByDesc('is_default')->value('id');

        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetValidation();
    }

    public function save(CreateAuction $create): void
    {
        $this->authorize('auctions.create');

        // As many decimal places as CreditAmount can hold, derived rather
        // than hardcoded -- the same pattern RulesetForm's own credit fields
        // use, so this form's validation moves automatically if the
        // re-denomination factor ever does.
        $places = CreditAmount::decimalPlaces();
        $creditPattern = $places > 0 ? "/^\d{1,12}(\.\d{1,{$places}})?$/" : '/^\d{1,12}$/';

        $potTargetProvided = trim($this->pot_target_credits) !== '';

        $validated = $this->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            // Re-checked here, on the server, as well as offered by the list: a
            // ruleset id in a request is not a capability to start an auction
            // under the earlier rule.
            'auction_ruleset_id' => ['required', 'integer', Rule::exists('auction_rulesets', 'id')
                ->where('bid_model', BidModel::CumulativeStep->value)],
            // A decimal string validated by shape, so no float is involved.
            'settlement_amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            // Optional: blank is a legitimate choice, not an omission -- it
            // means this auction has no second way to close. The shape and
            // positivity rules apply only once something has actually been
            // typed, so an empty field is never told it looks malformed.
            'pot_target_credits' => [
                'nullable',
                'string',
                Rule::when($potTargetProvided, ["regex:{$creditPattern}", $this->positiveCreditAmount()]),
            ],
        ], [
            'settlement_amount.regex' => 'Enter an amount in cedis, such as 100 or 100.50.',
            'pot_target_credits.regex' => 'Enter a number of credits, such as 500 or 0.0001, greater than zero -- or leave it blank.',
        ]);

        try {
            $create->handle(
                product: Product::findOrFail($validated['product_id']),
                ruleset: AuctionRuleset::findOrFail($validated['auction_ruleset_id']),
                settlementAmount: Money::fromDecimalString($validated['settlement_amount']),
                potTarget: $potTargetProvided
                    ? CreditAmount::fromDecimalString(trim($validated['pot_target_credits']))
                    : null,
                actor: auth()->user(),
            );
        } catch (DomainException $e) {
            $this->addError('settlement_amount', $e->getMessage());

            return;
        }

        $this->showForm = false;
        $this->reset('product_id', 'settlement_amount', 'pot_target_credits');

        session()->flash('status', 'Auction created as a draft. Review its snapshot, then publish it.');
    }

    /**
     * A shape-valid figure that parses to zero (or negative) is refused here.
     *
     * Laravel runs every rule for a field regardless of whether an earlier one
     * failed (there is no implicit `bail`), so this closure cannot assume the
     * regex rule beside it already passed -- it catches its own parse failure
     * defensively rather than letting CreditAmount's exception escape as an
     * uncaught error instead of a validation message. The same pattern
     * RulesetForm uses for its own credit fields.
     */
    private function positiveCreditAmount(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            try {
                if (! CreditAmount::fromDecimalString((string) $value)->isPositive()) {
                    $fail('Enter an amount greater than zero, or leave it blank.');
                }
            } catch (InvalidArgumentException) {
                // The regex rule on the same field already reports the shape
                // problem; nothing more to add here.
            }
        };
    }

    /**
     * The product chosen in the form, so its Buy Now price can be shown
     * beside the settlement field it must not be confused with.
     */
    public function selectedProduct(): ?Product
    {
        return $this->product_id === null ? null : Product::find($this->product_id);
    }

    /**
     * The products an auction may be created for: the ones an administrator
     * has explicitly opted into the auction channel.
     *
     * Eligibility is its own, deliberate column -- not "does it have a status" --
     * so the picker itself upholds the gate and an auction can never be
     * launched for a product nobody marked as auctionable. The opt-in is set on
     * the product under Catalog; an auction does not stamp it on.
     *
     * @return Collection<int, Product>
     */
    public function products(): Collection
    {
        return Product::query()
            ->where('auction_eligible', true)
            ->whereIn('status', [ProductStatus::Active, ProductStatus::Draft, ProductStatus::OutOfStock])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, AuctionRuleset>
     */
    public function rulesets(): Collection
    {
        // Active only. A draft ruleset is unfinished configuration, and an
        // archived one has been retired -- neither should govern a new auction.
        // And only the cumulative model: a ruleset made under the earlier rule is
        // not offered for a new auction.
        return AuctionRuleset::query()->active()->acceptingNewAuctions()->orderBy('name')->get();
    }

    public function render(): View
    {
        return view('livewire.admin.auctions.auction-manager', [
            'auctions' => $this->auctions(),
            'statuses' => AuctionStatus::cases(),
            'endingSoonHours' => self::ENDING_SOON_HOURS,
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Auction>
     */
    private function auctions(): LengthAwarePaginator
    {
        return Auction::query()
            ->with(['product', 'ruleset', 'winner', 'buyNowBuyer'])
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            // The clock decides, never the status. An auction past its end
            // time stays marked Live until the sweep notices.
            ->when($this->endingSoon, fn ($q) => $q
                ->whereIn('status', [AuctionStatus::Live, AuctionStatus::Closing])
                ->whereNotNull('ends_at')
                ->where('ends_at', '<=', Carbon::now()->addHours(self::ENDING_SOON_HOURS)))
            ->when($this->settlement === 'awaiting', fn ($q) => $q
                ->where('status', AuctionStatus::PendingSettlement))
            ->when($this->settlement === 'overdue', fn ($q) => $q
                ->where('status', AuctionStatus::PendingSettlement)
                ->whereNotNull('settlement_due_at')
                ->where('settlement_due_at', '<', Carbon::now()))
            ->when($this->search !== '', fn ($q) => $q->whereHas(
                'product',
                fn ($p) => $p->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('sku', 'like', '%'.$this->search.'%'),
            ))
            ->orderByDesc('id')
            ->paginate(20);
    }
}
