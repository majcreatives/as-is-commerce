<?php

declare(strict_types=1);

namespace App\Livewire\Newsletter;

use App\Models\NewsletterSubscriber;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The page a confirmation link lands on.
 *
 * REACHED BY EMAIL, WHICH MEANS IT MUST NEVER 500. Every failure mode here --
 * a spent link, a guessed token, an address already confirmed, a link forwarded
 * to somebody who has since changed their mind -- is somebody clicking a link on
 * a phone. Each one gets a plain page that says what happened. A 500 here would
 * tell a customer we are broken at the exact moment we are asking them to trust
 * us with an address.
 *
 * NO LOGIN AND NO CSRF TOKEN, deliberately. The token in the URL IS the
 * credential: 64 random characters, looked up with hash_equals, destroyed the
 * moment it works. Adding a session requirement would mean nobody could confirm
 * without being signed in, which defeats the purpose of confirming an address
 * you do not have an account for.
 *
 * THE LINK IS SPENT AFTER ONE USE. That is what stops an old confirmation email
 * from re-subscribing somebody who has since unsubscribed.
 */
class ConfirmSubscription extends Component
{
    #[Layout('components.layouts.app')]
    #[Title('Confirm your subscription')]

    /** How the visit ended, so the page can say which thing happened. */
    public string $outcome = 'confirmed';

    /**
     * Nothing about the address is put in a public property. The page renders an
     * outcome, not "you confirmed kwame@example.com", because this URL gets
     * copied, screenshotted and left in browser history.
     */
    public function mount(string $token): void
    {
        $subscriber = NewsletterSubscriber::findByConfirmationToken($token);

        if ($subscriber === null) {
            // No row, or a token that has already been spent. Both mean the same
            // thing to the person clicking, and both are almost always "they
            // already did this".
            $this->outcome = 'already';

            return;
        }

        // confirm() reports whether it changed anything, which distinguishes
        // "you are on the list now" from "you already were".
        $this->outcome = $subscriber->confirm() ? 'confirmed' : 'already';
    }

    public function render(): View
    {
        return view('livewire.newsletter.confirm');
    }
}
