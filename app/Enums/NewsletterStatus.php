<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an address on the newsletter list sits.
 *
 * THREE STATES, AND THE ORDER IS THE POINT.
 *
 *     pending -> subscribed
 *     pending -> unsubscribed
 *     unsubscribed -> pending
 *
 * A row starts `pending` because a filled-in form proves nothing: the person
 * typing it may not own the address. `subscribed` is only reachable by following
 * the emailed confirmation link, which is the only moment we have evidence that
 * somebody proved they own that inbox. `unsubscribed` is reachable from either
 * state, because asking to stop does not require having consented first.
 *
 * THERE IS NO `bounced` OR `complained` STATE, and that is a limitation rather
 * than an oversight. Nothing in this project sends the newsletter, so there is no
 * delivery feedback to record. If a sending mechanism is added later it will need
 * these states, and they should be added then -- with the webhook or bounce
 * handling that actually populates them, rather than as empty columns waiting to
 * be guessed at.
 *
 * `unsubscribed -> subscribed` IS DELIBERATELY NOT ALLOWED. Coming back means
 * going through confirmation again, so an old confirmation email cannot
 * resurrect somebody who has left, and a third party cannot quietly re-add them.
 */
enum NewsletterStatus: string
{
    /** A form was filled in. Nobody has proved they own the address yet. */
    case Pending = 'pending';

    /** The owner followed the confirmation link. On the list. */
    case Subscribed = 'subscribed';

    /** The owner asked to stop. */
    case Unsubscribed = 'unsubscribed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting confirmation',
            self::Subscribed => 'Subscribed',
            self::Unsubscribed => 'Unsubscribed',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pending => 'bg-amber-50 text-amber-800 ring-amber-200',
            self::Subscribed => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Unsubscribed => 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }

    /**
     * The only legal moves. Anything else is a bug in a caller.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Subscribed, self::Unsubscribed],
            self::Subscribed => [self::Unsubscribed],
            // Back to pending, never straight to subscribed.
            self::Unsubscribed => [self::Pending],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
