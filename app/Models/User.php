<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Notifications\ValueObjects\NotificationPreferences;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string|null $name
 * @property string $phone Canonical E.164, e.g. +233244123456
 * @property Carbon|null $phone_verified_at
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property UserStatus $status
 * @property array<string, mixed>|null $notification_preferences
 */
#[Fillable(['name', 'phone', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'notification_preferences' => 'array',
        ];
    }

    /**
     * Every bid this customer has ever placed.
     *
     * Historical facts, each chained to the credit transaction that paid for
     * it. Nothing here is reversible: the credits these consumed are gone,
     * whether the bid won, lost or was overtaken.
     *
     * @return HasMany<Bid, $this>
     */
    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    /**
     * This customer's own address book.
     *
     * A convenience, not a record of anything. Where a package actually went
     * lives on the delivery, which holds its own frozen copy -- so editing
     * these changes where the next order goes and nothing that has already
     * shipped.
     *
     * @return HasMany<Address, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class)->orderByDesc('is_default')->orderBy('id');
    }

    /**
     * This account's notifications.
     *
     * Overridden so the relation yields this platform's Notification model
     * rather than the framework's base class. Everything a template reads --
     * the title, the message, the link, whether it is unread -- lives on the
     * subclass, and without this the relation would hand back rows that
     * cannot answer any of it.
     *
     * @return MorphMany<Notification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable')->latest();
    }

    /**
     * @return MorphMany<Notification, $this>
     */
    public function unreadNotifications(): MorphMany
    {
        return $this->notifications()->whereNull('read_at');
    }

    /**
     * What this account wants to be told about.
     *
     * Null means every default, so an account that has never opened the
     * preferences screen needs no row and no backfill. Transactional
     * notifications reach them regardless of what is stored -- the value
     * object enforces that rather than each caller remembering it.
     */
    public function notificationPreferences(): NotificationPreferences
    {
        // Guarded because a user instance does not always carry every column.
        // A model just written -- by registration, or by a factory -- holds
        // only the attributes that were inserted, and strict mode throws on
        // reading one that was never loaded. Not loaded means nothing is known
        // about this account's preferences, and the defaults are exactly the
        // right answer to that.
        $stored = array_key_exists('notification_preferences', $this->getAttributes())
            ? $this->notification_preferences
            : null;

        return NotificationPreferences::fromArray($stored);
    }

    /**
     * How many notifications this account has not read.
     *
     * Runs on every authenticated page, so it counts against an index on
     * (notifiable, read_at) rather than loading the rows.
     */
    public function unreadNotificationCount(): int
    {
        return $this->notifications()->whereNull('read_at')->count();
    }

    /**
     * Give every new account its wallets immediately.
     *
     * Both start at zero, which is a real balance rather than a placeholder.
     * Creating them here rather than lazily means every query and screen can
     * assume a wallet exists, and there is no window in which a user has none.
     */
    protected static function booted(): void
    {
        static::created(function (self $user): void {
            $user->creditWallet()->create();
            $user->cashWallet()->create(['currency' => 'GHS']);
        });
    }

    /**
     * @return HasOne<CreditWallet, $this>
     */
    public function creditWallet(): HasOne
    {
        return $this->hasOne(CreditWallet::class);
    }

    /**
     * @return HasOne<CashWallet, $this>
     */
    public function cashWallet(): HasOne
    {
        return $this->hasOne(CashWallet::class);
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /**
     * Whether this account is currently permitted to authenticate.
     *
     * Checked server-side on every login attempt; never inferred from a
     * client-supplied value.
     */
    public function canAuthenticate(): bool
    {
        return $this->status->canAuthenticate();
    }
}
