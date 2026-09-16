<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's shopping basket: what they intend to buy, before any order.
 *
 * A cart is NOT an order. It holds no money, writes no ledger, and reserves no
 * stock -- nothing financial or commercial moves when its contents change, and
 * nothing here is authoritative about price, availability or ownership. Those
 * decisions belong to the placement action, which validates every line against
 * the inventory ledger and creates the order atomically.
 *
 * A cart is presentation state with a durable home: it survives a refresh, and
 * its quantity figures are a convenience for a customer, never a claim about
 * stock. If stock runs out while an item sits in a cart, the placement action
 * says so rather than this model inventing a reservation.
 *
 * @property int $id
 * @property int $user_id
 */
class Cart extends Model
{
    /**
     * The user is set by the service, never by request input.
     *
     * @var list<string>
     */
    protected $fillable = ['user_id'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * The number of distinct items (lines), not pieces.
     */
    public function lineCount(): int
    {
        return $this->items()->count();
    }

    /**
     * The total quantity across all lines, for a basket badge.
     */
    public function quantity(): int
    {
        return (int) $this->items()->sum('quantity');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }
}
