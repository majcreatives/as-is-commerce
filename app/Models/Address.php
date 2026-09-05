<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One place a customer has told us about.
 *
 * THIS IS A CONVENIENCE, NOT A RECORD. It exists so somebody does not retype
 * their street every time they buy something. Editing one changes where their
 * *next* order goes and nothing else: a delivery copies the address it is
 * going to rather than pointing at this row, so a customer who moves house in
 * March cannot silently redirect a package that went out in February.
 *
 * That is the whole reason these are two tables instead of one. Without the
 * copy, "where did order X actually go" would be unanswerable the moment
 * anybody tidied their address book.
 *
 * GHANA-SHAPED, AND FORGIVING. Many addresses here are a description and a
 * landmark rather than a street number, so only the parts that are always
 * meaningful -- who receives it, a number to call, something to find, and a
 * town -- are required. A GhanaPostGPS code is welcome and never demanded:
 * most people do not know theirs, and requiring it would block the checkout of
 * everybody who does not.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $label
 * @property string $recipient_name
 * @property string $recipient_phone
 * @property string $address_line
 * @property string|null $area
 * @property string $city
 * @property string|null $region
 * @property string|null $digital_address
 * @property string|null $landmark
 * @property string|null $instructions
 * @property bool $is_default
 */
class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    /**
     * Nothing is mass assignable.
     *
     * `user_id` especially: an address is written by one service which sets
     * the owner from the authenticated session, never from request input. A
     * fillable owner is how somebody files an address under another person's
     * account.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * The address on one line, for a listing.
     */
    public function summary(): string
    {
        return implode(', ', array_filter([
            $this->address_line,
            $this->area,
            $this->city,
            $this->region,
        ]));
    }

    /**
     * What this address is called, falling back to something recognisable.
     */
    public function displayLabel(): string
    {
        return $this->label ?? $this->city;
    }

    /**
     * The fields a delivery copies.
     *
     * Kept here rather than at the copying call site, so adding a field to an
     * address is one change rather than two that can drift apart.
     *
     * @return array<string, string|null>
     */
    public function toSnapshot(): array
    {
        return [
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'address_line' => $this->address_line,
            'area' => $this->area,
            'city' => $this->city,
            'region' => $this->region,
            'digital_address' => $this->digital_address,
            'landmark' => $this->landmark,
            'instructions' => $this->instructions,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
