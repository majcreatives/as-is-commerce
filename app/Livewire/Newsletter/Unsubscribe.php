<?php

declare(strict_types=1);

namespace App\Livewire\Newsletter;

use App\Models\NewsletterSubscriber;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The page an unsubscribe link lands on.
 *
 * IT TAKES EFFECT IMMEDIATELY, WITH NO CONFIRMATION STEP AND NO LOGIN. Adding a
 * "are you sure?" to an unsubscribe makes staying subscribed the default, which
 * is the opposite of what the link is for; and this link has to work for
 * somebody who never had an account.
 *
 * WORKS FROM `pending` TOO. Somebody who asked to stop before confirming has
 * expressed the same wish as somebody who confirmed first. If a pending row
 * stayed confirmable, an old confirmation email could come back to life and
 * override a request to leave.
 *
 * THE TOKEN IS NEVER ROTATED HERE. This link may be sitting in a campaign email
 * from years away and it has to keep working, which is why it is persisted
 * instead of being burned the way the confirmation token is.
 */
class Unsubscribe extends Component
{
    #[Layout('components.layouts.app')]
    #[Title('Unsubscribe')]
    public string $outcome = 'unsubscribed';

    public function mount(string $token): void
    {
        $subscriber = NewsletterSubscriber::findByUnsubscribeToken($token);

        if ($subscriber === null) {
            // Never treat "we could not find that" as "you are still subscribed".
            // A wrong-but-plausible answer here would leave somebody believing
            // they had stopped hearing from us.
            $this->outcome = 'unknown';

            return;
        }

        $this->outcome = $subscriber->unsubscribe() ? 'unsubscribed' : 'already';
    }

    public function render(): View
    {
        return view('livewire.newsletter.unsubscribe');
    }
}
