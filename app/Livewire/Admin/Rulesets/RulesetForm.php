<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Rulesets;

use App\Domain\Auction\Actions\CreateRuleset;
use App\Domain\Auction\Actions\UpdateRuleset;
use App\Domain\Auction\RulesetInvariants;
use App\Domain\Shared\Money\Money;
use App\Enums\ForfeitPolicy;
use App\Models\AuctionRuleset;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class RulesetForm extends Component
{
    public ?AuctionRuleset $ruleset = null;

    // Basic
    public string $name = '';

    public string $description = '';

    // Bidding. Bids carry their own variable amounts; these constrain which
    // amounts are acceptable. Held as strings so an empty field means "no
    // rule" rather than collapsing to zero.
    public string $minimum_bid_credits = '';

    public string $minimum_bid_increment_credits = '';

    public string $allow_bid_increase = '';

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

        $this->minimum_bid_credits = (string) ($ruleset->minimum_bid_credits ?? '');
        $this->minimum_bid_increment_credits = (string) ($ruleset->minimum_bid_increment_credits ?? '');
        $this->allow_bid_increase = $ruleset->allow_bid_increase === null
            ? ''
            : ($ruleset->allow_bid_increase ? 'yes' : 'no');
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
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],

            // Nullable: these business values are undecided, and an empty
            // field must mean "no rule" rather than a number nobody chose.
            'minimum_bid_credits' => ['nullable', 'string', 'regex:/^\d{1,12}$/'],
            'minimum_bid_increment_credits' => ['nullable', 'string', 'regex:/^\d{1,12}$/'],
            'allow_bid_increase' => ['nullable', Rule::in(['', 'yes', 'no'])],
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
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'minimum_bid_credits.regex' => 'Enter a whole number of credits, or leave blank for no minimum.',
            'minimum_bid_increment_credits.regex' => 'Enter a whole number of credits, or leave blank for no minimum.',
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
            $update->handle($this->ruleset, $attributes, auth()->user());
            session()->flash('status', 'Draft ruleset updated.');
        } else {
            $this->authorize('auction_rulesets.create');
            $created = $create->handle($attributes, auth()->user());
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

        $minimumBid = trim($this->minimum_bid_credits);
        $minimumIncrement = trim($this->minimum_bid_increment_credits);

        return [
            'name' => $this->name,
            'description' => $this->description !== '' ? $this->description : null,

            // Blank stays null: an unset bid rule is not the same as a rule
            // of zero, and the business has not chosen either value yet.
            'minimum_bid_credits' => $minimumBid === '' ? null : (int) $minimumBid,
            'minimum_bid_increment_credits' => $minimumIncrement === '' ? null : (int) $minimumIncrement,
            'allow_bid_increase' => match ($this->allow_bid_increase) {
                'yes' => true,
                'no' => false,
                default => null,
            },
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
        ]);
    }
}
