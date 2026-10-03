<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NewsletterStatus;
use Database\Factories\NewsletterSubscriberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One address somebody asked to hear from us.
 *
 * The three states and the moves between them are the whole of the model, and
 * each move is written once, here:
 *
 *   request()  -> pending        a form was filled in; nobody proved anything
 *   confirm()  -> subscribed     the owner followed the emailed link
 *   unsubscribe() -> unsubscribed the owner asked to stop, from any email
 *
 * All three are idempotent and none of them throws. A confirmation link that a
 * customer clicks twice, or an unsubscribe link clicked after they have already
 * left, must not become an error page -- both happen constantly, because people
 * search old emails rather than keeping track. Idempotency here is the difference
 * between "you're unsubscribed" and a 500 on somebody's phone.
 *
 * unsubscribe() ALSO WORKS FROM `pending`. Somebody who asked to stop before
 * confirming has expressed the same wish as somebody who confirmed first, and
 * leaving the row confirmable would let a later click undo the request.
 *
 * @property int $id
 * @property string $email
 * @property NewsletterStatus $status
 * @property string|null $confirmation_token
 * @property string $unsubscribe_token
 * @property Carbon|null $consented_at
 * @property Carbon|null $unsubscribed_at
 * @property string|null $source
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class NewsletterSubscriber extends Model
{
    /** @use HasFactory<NewsletterSubscriberFactory> */
    use HasFactory;

    /**
     * Only what an incoming request may set. Deliberately excludes both tokens,
     * `status`, `consented_at` and `unsubscribed_at`: those are written by
     * request()/confirm()/unsubscribe() and by nothing else, so a mass-assigned
     * value can never come from a form.
     */
    protected $fillable = [
        'email',
        'source',
    ];

    /**
     * Confirmation tokens are random and long enough that guessing one is not a
     * viable way to subscribe a stranger or to stop somebody who did not ask.
     */
    private const TOKEN_BYTES = 32;

    protected function casts(): array
    {
        return [
            'status' => NewsletterStatus::class,
            'consented_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    /**
     * Put an address into the pending state, or leave it alone if it is already
     * there.
     *
     * AN EXISTING SUBSCRIBER IS NOT DISTURBED. Asking again is a no-op, not a
     * reset to pending: re-subscribing somebody who is already subscribed would
     * silently invalidate their unsubscribe link and put them back in the queue
     * for an email they never asked for.
     *
     * AN UNSUBSCRIBER IS PUT BACK TO PENDING, with a fresh confirmation token.
     * That is deliberate friction. They have to prove they still want to be on
     * the list rather than being re-added by one stray form submission -- which
     * would otherwise make unsubscribe something a third party can undo.
     *
     * ONLY THE CONFIRMATION TOKEN IS ROTATED. The unsubscribe token is written
     * once, when the row is created, and never changes again. That is not an
     * oversight: a campaign email may already be sitting in this person's inbox
     * carrying the current unsubscribe link, and rotating the token when they
     * re-subscribe would leave a link that silently fails to unsubscribe them.
     * A link that stops working is the one thing this whole feature must never
     * produce.
     *
     * Tokens are assigned with forceFill rather than added to $fillable. They are
     * generated here and nowhere else, so they should not be something an
     * incoming request could name.
     */
    public static function request(string $email, ?string $source = null): self
    {
        $normalized = self::normalize($email);

        $existing = static::query()->where('email', $normalized)->first();

        if ($existing !== null && $existing->status === NewsletterStatus::Subscribed) {
            return $existing;
        }

        if ($existing !== null) {
            $existing->forceFill([
                'status' => NewsletterStatus::Pending,
                'confirmation_token' => Str::random(self::TOKEN_BYTES * 2),
                'source' => $source ?? $existing->source,
                'consented_at' => null,
                'unsubscribed_at' => null,
            ])->save();

            return $existing;
        }

        $subscriber = new self;
        $subscriber->forceFill([
            'email' => $normalized,
            'source' => $source,
            // Set here rather than left to the column default: the default only
            // applies on INSERT, so an unsaved attribute would leave the in-memory
            // model's status null. Callers decide what to do next by asking the
            // model whether it awaits confirmation, and a null status would make
            // them answer "no" for a brand new subscriber -- so the confirmation
            // email would silently never be queued.
            'status' => NewsletterStatus::Pending,
            'confirmation_token' => Str::random(self::TOKEN_BYTES * 2),
            'unsubscribe_token' => Str::random(self::TOKEN_BYTES * 2),
        ]);
        $subscriber->save();

        return $subscriber;
    }

    /**
     * The owner has proved they own the address.
     *
     * The token is destroyed on success, so the link in that email is spent.
     * Re-subscribing from scratch issues a new one.
     */
    public function confirm(): bool
    {
        if ($this->status === NewsletterStatus::Subscribed) {
            return false;
        }

        $this->forceFill([
            'status' => NewsletterStatus::Subscribed,
            'consented_at' => now(),
            'unsubscribed_at' => null,
            'confirmation_token' => null,
        ])->save();

        return true;
    }

    /**
     * The owner has asked to stop. Applies from any state.
     */
    public function unsubscribe(): bool
    {
        if ($this->status === NewsletterStatus::Unsubscribed) {
            return false;
        }

        $this->forceFill([
            'status' => NewsletterStatus::Unsubscribed,
            'unsubscribed_at' => now(),
            // Spent, so the confirmation email that created this row cannot come
            // back to life and re-subscribe them.
            'confirmation_token' => null,
        ])->save();

        return true;
    }

    public function isSubscribed(): bool
    {
        return $this->status === NewsletterStatus::Subscribed;
    }

    /**
     * Whether this request actually needs a confirmation email sent.
     *
     * Returned rather than sent inline, because the caller decides whether it is
     * safe to send right now: the signup component wants to queue it, and the
     * "resend" path wants to refuse a second one.
     */
    public function awaitsConfirmation(): bool
    {
        return $this->status === NewsletterStatus::Pending
            && $this->confirmation_token !== null;
    }

    /**
     * Find a row by confirmation token, or null.
     *
     * hash_equals rather than `where('token', $t)`: the lookup has already
     * established existence, and constant-time comparison is what keeps a token
     * from being recovered one character at a time.
     */
    public static function findByConfirmationToken(string $token): ?self
    {
        return static::query()
            ->whereNotNull('confirmation_token')
            ->get()
            ->first(fn (self $subscriber): bool => hash_equals((string) $subscriber->confirmation_token, $token));
    }

    /**
     * Find a row by unsubscribe token, or null.
     *
     * Unsubscribe deliberately keeps its token valid forever, so this is the
     * lookup that has to still succeed in three years.
     */
    public static function findByUnsubscribeToken(string $token): ?self
    {
        return static::query()
            ->whereNotNull('unsubscribe_token')
            ->get()
            ->first(fn (self $subscriber): bool => hash_equals((string) $subscriber->unsubscribe_token, $token));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSubscribed(Builder $query): Builder
    {
        return $query->where('status', NewsletterStatus::Subscribed->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', NewsletterStatus::Pending->value);
    }

    /**
     * The link that proves this address belongs to whoever clicks it.
     *
     * Null when there is nothing to prove -- confirmed, unsubscribed, or token
     * spent. Returning null rather than a URL built around a missing token
     * means a caller cannot accidentally email a link that can never work.
     *
     * Built here rather than in the job or the view so a token is never turned
     * into a URL in more than one place.
     */
    public function confirmationUrl(): ?string
    {
        if ($this->confirmation_token === null) {
            return null;
        }

        return route('newsletter.confirm', ['token' => $this->confirmation_token]);
    }

    /**
     * The link that takes somebody off the list. Available in every state,
     * including `pending` -- see the note in NewsletterConfirmationMail about
     * mail clients looking for this in the very first email.
     */
    public function unsubscribeUrl(): string
    {
        return route('newsletter.unsubscribe', ['token' => $this->unsubscribe_token]);
    }

    /**
     * The one place an address is put into the shape we store and compare.
     *
     * Trimmed and lowercased, because the unique index treats
     * "Kwame@Example.com " and "kwame@example.com" as two people, and one
     * person subscribed twice is a bug a customer would see as us mailing them
     * twice.
     *
     * Deliberately NOT punycode-encoded or plus-tag-stripped: those change what
     * an address means, and the confirmation link is the only proof we have.
     */
    public static function normalize(string $email): string
    {
        return Str::lower(trim($email));
    }
}
