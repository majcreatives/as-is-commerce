<?php

declare(strict_types=1);

namespace App\Livewire\Credits;

use App\Domain\Payments\Actions\InitializeCreditPurchase;
use App\Domain\Payments\Exceptions\PaymentGatewayError;
use App\Domain\Payments\Exceptions\PaymentVerificationFailed;
use App\Models\CreditPackage;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Where a customer buys credits.
 *
 * The component takes a package slug and nothing else. It never accepts a
 * price or a credit quantity from the browser: both are read server-side from
 * the package and frozen onto the purchase before Paystack is contacted.
 */
#[Layout('components.layouts.app')]
#[Title('Buy credits')]
class CreditPackages extends Component
{
    public function purchase(string $slug, InitializeCreditPurchase $action): mixed
    {
        // Scoped to purchasable packages, so an inactive one cannot be bought
        // by guessing its slug.
        $package = CreditPackage::query()->active()->where('slug', $slug)->first();

        if ($package === null) {
            $this->addError('package', 'That credit package is not available.');

            return null;
        }

        try {
            $result = $action->handle(auth()->user(), $package);
        } catch (PaymentVerificationFailed|PaymentGatewayError $e) {
            $this->addError('package', $e->getMessage());

            return null;
        }

        // Off to the provider's hosted page. Everything after this is decided
        // server-side, whatever the browser comes back saying.
        return $this->redirect($result['transaction']->authorizationUrl);
    }

    /**
     * @return Collection<int, CreditPackage>
     */
    public function packages(): Collection
    {
        return CreditPackage::query()->purchasable()->get();
    }

    public function render(): View
    {
        return view('livewire.credits.credit-packages', [
            'packages' => $this->packages(),
            // Every user is given a wallet on creation, but ?? 0 keeps the
            // page renderable for an account predating that.
            'creditBalance' => auth()->user()->creditWallet->balance ?? 0,
        ]);
    }
}
