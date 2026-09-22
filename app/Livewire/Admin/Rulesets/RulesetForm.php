<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Rulesets;

use App\Domain\Auction\Actions\CreateRuleset;
use App\Domain\Auction\Actions\UpdateRuleset;
use App\Domain\Auction\RulesetInvariants;
use App\Domain\Credit\ValueObjects\CreditAmount;
use App\Domain\Shared\Money\Money;
use App\Enums\BidModel;
use App\Enums\ForfeitPolicy;
use App\Models\AuctionRuleset;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class RulesetForm extends Component
{
    public ?AuctionRuleset $ruleset = null;

    // Basic
    public string $name = '';

    public string $description = '';

    // Bidding. Under the cumulative model a bidder's position is the total
    // credits they have consumed, and every bid lands exactly one step ahead of
    // the leader. Both figures are REQUIRED: the minimum bid is the opening bid,
    // the only opening figure that is not invented, and the step is what every
    // later bid is measured by. Held as strings so an empty field is refused
    // rather than collapsing to zero.
    //
    // ENTERED AND DISPLAYED IN CREDITS, STORED IN SUBCREDITS. An administrator
    // types what a customer would recognise -- "1", "0.0001" -- exactly as
    // CreditAmount::fromDecimalString() parses it; toAttributes() converts to
    // the raw ledger count the database actually holds, and fillFrom() converts
    // back the same way when an existing draft is opened. Casting the raw
    // typed string straight to (int), as this form used to, would silently
    // truncate any admin figure finer than a whole credit to zero.
    public string $minimum_bid_credits = '';

    public string $bid_increment_credits = '';

    public int $minimum_bid_interval_ms = 3000;

    // Timing
    public int $base_duration_seconds = 300;

    public int $closing_window_seconds = 10;

    public int $extension_seconds = 10;

    public int $max_extensions = 20;

    public int $max_extension_total_seconds = 300;

    // Winner / checkout
    public int $checkout_deadline_minutes = 60;

    public string $forfeit_policy = 'relist';

    // Buy Now
    public bool $buy_now_enabled = true;

    public bool $buy_now_credit_discount_enabled = true;

    // Pricing. Entered as a decimal string and converted on save.
    public string $delivery_fee = '0.00';

    public string $currency = 'GHS';

    public int $tax_bps = 0;

    public function mount(?AuctionRuleset $ruleset = null): void
    {
        if ($ruleset?->exists) {
            $this->authorize('auction_rulesets.update');

            abort_unless($ruleset->isEditable(), 403, 'Only draft rulesets can be edited.');

            $this->ruleset = $ruleset;
            $this->fillFrom($ruleset);

            return;
        }

        $this->authorize('auction_rulesets.create');
    }

    private function fillFrom(AuctionRuleset $ruleset): void
    {
        $this->name = $ruleset->name;
        $this->description = $ruleset->description ?? '';

        $this->minimum_bid_credits = $ruleset->minimum_bid_credits === null
            ? '' : CreditAmount::fromSubcredits($ruleset->minimum_bid_credits)->toDecimalString();
        $this->bid_increment_credits = $ruleset->bid_increment_credits === null
            ? '' : CreditAmount::fromSubcredits($ruleset->bid_increment_credits)->toDecimalString();
        $this->minimum_bid_interval_ms = $ruleset->minimum_bid_interval_ms;

        $this->base_duration_seconds = $ruleset->base_duration_seconds;
        $this->closing_window_seconds = $ruleset->closing_window_seconds;
        $this->extension_seconds = $ruleset->extension_seconds;
        $this->max_extensions = $ruleset->max_extensions;
        $this->max_extension_total_seconds = $ruleset->max_extension_total_seconds;

        $this->checkout_deadline_minutes = $ruleset->checkout_deadline_minutes;
        $this->forfeit_policy = $ruleset->forfeit_policy->value;

        $this->buy_now_enabled = $ruleset->buy_now_enabled;
        $this->buy_now_credit_discount_enabled = $ruleset->buy_now_credit_discount_enabled;
        $this->delivery_fee = $ruleset->deliveryFee()->toDecimalString();
        $this->currency = $ruleset->currency;
        $this->tax_bps = $ruleset->tax_bps;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        // As many decimal places as CreditAmount can hold, derived rather than
        // hardcoded, so this form's validation moves automatically if the
        // re-denomination factor ever does. 4 places today: "0.0001" is the
        // finest bid a cumulative ruleset can specify.
        $places = CreditAmount::decimalPlaces();
        $creditPattern = $places > 0 ? "/^\d{1,12}(\.\d{1,{$places}})?$/" : '/^\d{1,12}$/';

        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],

            // Both required, and at least one subcredit: an empty field must be
            // refused, never quietly become a number nobody chose. The regex
            // alone cannot refuse a shape-valid zero (it mirrors how
            // delivery_fee's regex legitimately allows zero), so positivity is
            // checked separately, after the value can actually be parsed.
            'minimum_bid_credits' => ['required', 'string', "regex:{$creditPattern}", $this->positiveCreditAmount()],
            'bid_increment_credits' => ['required', 'string', "regex:{$creditPattern}", $this->positiveCreditAmount()],
            'minimum_bid_interval_ms' => ['required', 'integer', 'min:0', 'max:600000'],

            'base_duration_seconds' => ['required', 'integer', 'min:1', 'max:2592000'],
            'closing_window_seconds' => ['required', 'integer', 'min:0'],
            'extension_seconds' => ['required', 'integer', 'min:0', 'max:3600'],
            'max_extensions' => ['required', 'integer', 'min:0', 'max:10000'],
            'max_extension_total_seconds' => ['required', 'integer', 'min:0', 'max:2592000'],

            'checkout_deadline_minutes' => ['required', 'integer', 'min:1', 'max:43200'],
            'forfeit_policy' => ['required', Rule::enum(ForfeitPolicy::class)],

            'buy_now_enabled' => ['boolean'],
            'buy_now_credit_discount_enabled' => ['boolean'],

            // Decimal strings, never floats.
            'delivery_fee' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'tax_bps' => ['required', 'integer', 'min:0', 'max:10000'],
        ];
    }

    /**
     * A shape-valid figure that parses to zero (or negative) is refused here.
     *
     * Laravel runs every rule for a field regardless of whether an earlier one
     * failed (there is no implicit `bail`), so this closure cannot assume the
     * regex rule beside it already passed -- it catches its own parse failure
     * defensively rather than letting CreditAmount's exception escape as an
     * uncaught error instead of a validation message.
     */
    private function positiveCreditAmount(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            try {
                if (! CreditAmount::fromDecimalString((string) $value)->isPositive()) {
                    $fail('Enter an amount greater than zero.');
                }
            } catch (InvalidArgumentException) {
                // The regex rule on the same field already reports the shape
                // problem; nothing more to add here.
            }
        };
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'minimum_bid_credits.required' => 'Enter the opening bid: the smallest bid, and the first one on every auction.',
            'minimum_bid_credits.regex' => 'Enter a number of credits, such as 1 or 0.0001, greater than zero.',
            'bid_increment_credits.required' => 'Enter the bid increment: how far ahead of the leader every bid lands a bidder.',
            'bid_increment_credits.regex' => 'Enter a number of credits, such as 1 or 0.0001, greater than zero.',
            'delivery_fee.regex' => 'Enter an amount such as 0 or 25.00, with no currency symbol.',
        ];
    }

    public function save(CreateRuleset $create, UpdateRuleset $update): void
    {
        $this->validate();

        // Cross-field checks run after per-field validation so the
        // administrator sees the specific field problems first.
        $attributes = $this->toAttributes();

        $problems = RulesetInvariants::problems($attributes);

        if ($problems !== []) {
            throw ValidationException::withMessages(['invariants' => $problems]);
        }

        if ($this->isEditing() && $this->ruleset !== null) {
            $this->authorize('auction_rulesets.update');
            $update->handle($this->ruleset, $attributes, auth()->user(), BidModel::CumulativeStep);
            session()->flash('status', 'Draft ruleset updated.');
        } else {
            $this->authorize('auction_rulesets.create');
            $created = $create->handle($attributes, auth()->user(), BidModel::CumulativeStep);
            session()->flash(
                'status',
                "Draft ruleset [{$created->name} v{$created->version}] created. Activate it when you are ready."
            );
        }

        $this->redirectRoute('admin.rulesets.index', navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(): array
    {
        $currency = strtoupper($this->currency);

        // Parsed the same way Money is elsewhere in this form: from a decimal
        // string, never by casting, so a figure finer than a whole credit
        // ("0.0001") is converted to its exact subcredit count rather than
        // truncated to zero. Validation has already confirmed both parse and
        // are positive by the time save() reaches this method.
        $minimumBid = CreditAmount::fromDecimalString(trim($this->minimum_bid_credits))->subcredits;
        $increment = CreditAmount::fromDecimalString(trim($this->bid_increment_credits))->subcredits;

        return [
            'name' => $this->name,
            'description' => $this->description !== '' ? $this->description : null,

            'minimum_bid_credits' => $minimumBid,
            'bid_increment_credits' => $increment,

            // The single-highest rules are cleared, explicitly, not merely left
            // out: saving here moves a draft to the cumulative model, and the
            // database refuses those fields beside it. Without the nulls, an
            // older draft's lower-bound increment would survive the move.
            'minimum_bid_increment_credits' => null,
            'allow_bid_increase' => null,
            'minimum_bid_interval_ms' => $this->minimum_bid_interval_ms,

            'base_duration_seconds' => $this->base_duration_seconds,
            'closing_window_seconds' => $this->closing_window_seconds,
            'extension_seconds' => $this->extension_seconds,
            'max_extensions' => $this->max_extensions,
            'max_extension_total_seconds' => $this->max_extension_total_seconds,

            'checkout_deadline_minutes' => $this->checkout_deadline_minutes,
            'forfeit_policy' => $this->forfeit_policy,

            'buy_now_enabled' => $this->buy_now_enabled,
            'buy_now_credit_discount_enabled' => $this->buy_now_credit_discount_enabled,
            'delivery_fee_minor' => Money::fromDecimalString($this->delivery_fee, $currency)->minor,
            'currency' => $currency,
            'tax_bps' => $this->tax_bps,
        ];
    }

    /**
     * Whether this form is editing an existing draft rather than creating one.
     */
    private function isEditing(): bool
    {
        return $this->ruleset !== null && $this->ruleset->exists;
    }

    public function render(): View
    {
        return view('livewire.admin.rulesets.ruleset-form', [
            'forfeitPolicies' => ForfeitPolicy::cases(),
            'isEditing' => $this->isEditing(),
            // An older draft edited here is moved to the cumulative model when
            // saved. Said on the form, so nobody is surprised by it.
            'movesToCumulative' => $this->isEditing()
                && $this->ruleset !== null
                && $this->ruleset->bid_model !== BidModel::CumulativeStep,
        ]);
    }
}
