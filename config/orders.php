<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Orders and checkout
|--------------------------------------------------------------------------
|
| Wiring, not business rules. Anything a person would want to change -- how
| long a checkout holds stock, what delivery costs, what tax applies -- lives
| in the settings table where an administrator owns it, or in an auction's own
| frozen snapshot. Nothing commercial is configured here.
|
*/

return [
    /*
     | Where Paystack returns the customer's browser after paying for a
     | product. Kept separate from the credit-purchase callback because the
     | two settle entirely different things, and a shared endpoint would have
     | to guess which. Neither is ever treated as proof of payment.
     */
    'callback_route' => 'checkout.callback',
];
