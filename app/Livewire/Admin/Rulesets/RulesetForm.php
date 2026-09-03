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

    // Bidding
    public int $bid_cost_credits = 1;

    public bool $unique_leader = true;

    public int $minimum_bid_interval_ms = 1000;

    // Timing
    public int $base_duration_seconds = 300;

    public int $closing_window_seconds = 10;

    public int $extension_seconds = 10;

    public int $max_extensions = 20;

    public int $max_extension_total_seconds = 300;

    // Winner / checkout
    public int $checkout_deadline_minutes = 60;

    public string $forfeit_policy = 'relist';

    // Pricing. Entered as a decimal string and converted to minor units on
    // save -- the form never holds a float.
    public string $default_checkout_price = '';

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

        $this->bid_cost_credits = $ruleset->bid_cost_credits;
        $this->unique_leader = $ruleset->unique_leader;
        $this->minimum_bid_interval_ms = $ruleset->minimum_bid_interval_ms;

        $this->base_duration_seconds = $ruleset->base_duration_seconds;
        $this->closing_window_seconds = $ruleset->closing_window_seconds;
        $this->extension_seconds = $ruleset->extension_seconds;
        $this->max_extensions = $ruleset->max_extensions;
        $this->max_extension_total_seconds = $ruleset->max_extension_total_seconds;

        $this->checkout_deadline_minutes = $ruleset->checkout_deadline_minutes;
        $this->forfeit_policy = $ruleset->forfeit_policy->value;

        $this->default_checkout_price = $ruleset->defaultCheckoutPrice()?->toDecimalString() ?? '';
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

            'bid_cost_credits' => ['required', 'integer', 'min:1'],
            'unique_leader' => ['boolean'],
            'minimum_bid_interval_ms' => ['required', 'integer', 'min:0', 'max:600000'],

            'base_duration_seconds' => ['required', 'integer', 'min:1', 'max:2592000'],
            'closing_window_seconds' => ['required', 'integer', 'min:0'],
            'extension_seconds' => ['required', 'integer', 'min:0', 'max:3600'],
            'max_extensions' => ['required', 'integer', 'min:0', 'max:10000'],
            'max_extension_total_seconds' => ['required', 'integer', 'min:0', 'max:2592000'],

            'checkout_deadline_minutes' => ['required', 'integer', 'min:1', 'max:43200'],
            'forfeit_policy' => ['required', Rule::enum(ForfeitPolicy::class)],

            // Decimal strings, never floats.
            'default_checkout_price' => ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
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
            'default_checkout_price.regex' => 'Enter an amount such as 5500 or 5500.00, with no currency symbol.',
            'delivery_fee.regex' => 'Enter an amount such as 0 or 25.00, with no currency symbol.',
            'bid_cost_credits.min' => 'A bid must cost at least 1 credit.',
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

        $checkoutPrice = trim($this->default_checkout_price) === ''
            ? null
            : Money::fromDecimalString($this->default_checkout_price, $currency)->minor;

        return [
            'name' => $this->name,
            'description' => $this->description !== '' ? $this->description : null,

            'bid_cost_credits' => $this->bid_cost_credits,
            'unique_leader' => $this->unique_leader,
            'minimum_bid_interval_ms' => $this->minimum_bid_interval_ms,

            'base_duration_seconds' => $this->base_duration_seconds,
            'closing_window_seconds' => $this->closing_window_seconds,
            'extension_seconds' => $this->extension_seconds,
            'max_extensions' => $this->max_extensions,
            'max_extension_total_seconds' => $this->max_extension_total_seconds,

            'checkout_deadline_minutes' => $this->checkout_deadline_minutes,
            'forfeit_policy' => $this->forfeit_policy,

            'default_checkout_price_minor' => $checkoutPrice,
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
