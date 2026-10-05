<?php

declare(strict_types=1);

namespace App\Livewire\Newsletter;

use App\Jobs\SendNewsletterConfirmation;
use App\Models\NewsletterSubscriber;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * The newsletter signup form.
 *
 * Rendered inside a modal on the front page, which is where it lived in the
 * footer before. Nothing else about it changed: the same form, the same rules,
 * the same wording. Where it appears is a presentation decision and this
 * component is deliberately unaware of it.
 *
 * SUBMITTING DOES NOT SUBSCRIBE ANYBODY. It records that somebody asked, and
 * queues them a confirmation link. They are on the list only once they have
 * followed it -- which is what stops one person subscribing a stranger, and what
 * makes the stored `consented_at` mean that they own the address rather than that
 * they once typed it.
 *
 * THE RESPONSE NEVER SAYS WHETHER THE ADDRESS WAS ALREADY ON THE LIST. If a
 * field told the truth either way it would be an address-testing oracle: anybody
 * could type an address and learn whether a given customer is subscribed to us,
 * which is customer information we have no business handing out. Both outcomes
 * -- "we'll email you a link" and "you were already subscribed, we'll leave you
 * alone" -- produce the same message, and so does a malformed address that
 * passed validation.
 *
 * THE COPY PROMISES NOTHING ABOUT FREQUENCY. See the note in
 * NewsletterConfirmationMail: nothing in this project can send a newsletter yet,
 * and a prompt promising a weekly series we cannot currently deliver is a broken
 * promise made to somebody who has only read it.
 */
class Signup extends Component
{
    /**
     * The address, deliberately NOT bound as a public property on the model.
     * `#[Validate]` without a model binding is used throughout this project for
     * exactly this reason.
     */
    #[Validate('required|email:rfc|max:254')]
    public string $email = '';

    /**
     * Whether the "check your inbox" message is showing.
     *
     * Set after a submit, and not derived from whether anything was actually
     * sent -- because telling the user is the same either way.
     */
    public bool $confirmationRequested = false;

    /**
     * Throttled per address rather than per session, for the reason
     * ForgotPassword gives: a limit that resets on a new session can be walked
     * past by reloading, and one keyed on the session can be shared around.
     */
    private const MAX_ATTEMPTS = 3;

    private const DECAY_SECONDS = 300;

    public function requestConfirmation(): void
    {
        // Normalize BEFORE validating, not after. People paste addresses, and a
        // pasted address very often arrives with a trailing space or a capital
        // letter on the domain. Validating the raw string first would reject it
        // and tell somebody their own address is invalid, which is both untrue
        // and the kind of thing that makes them give up on the form.
        $this->email = NewsletterSubscriber::normalize($this->email);

        $this->validate();

        $throttleKey = $this->throttleKey();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Too many requests. Please try again in '
                    .RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }

        RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

        $subscriber = NewsletterSubscriber::request(
            email: $this->email,
            source: 'modal',
        );

        // Only queue when this particular request actually produced a token to
        // send. A customer who is already subscribed returns a row that is
        // awaiting nothing, and mailing them a fresh link would be noise.
        if ($subscriber->awaitsConfirmation()) {
            SendNewsletterConfirmation::dispatch((string) $subscriber->id);
        }

        $this->confirmationRequested = true;

        // Clear the field, so a shared or public machine is not left with the
        // address sitting in it. The confirmation message carries the meaning
        // from here.
        $this->email = '';
        $this->resetValidation();
    }

    /**
     * Keyed on the normalized address rather than the raw string, so
     * "Kwame@x.com " and "kwame@x.com" share one budget instead of two.
     */
    private function throttleKey(): string
    {
        return 'newsletter-signup:'.NewsletterSubscriber::normalize($this->email);
    }

    public function render(): View
    {
        return view('livewire.newsletter.signup');
    }
}
