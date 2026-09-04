<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What caused a movement of stock.
 *
 * Sign convention, applied without exception:
 *
 *   positive delta = stock added
 *   negative delta = stock removed or set aside
 *
 * Several of these are not used yet. Reservation and Release will be driven
 * by the future Buy Now checkout, Sale by its completion, and Return by
 * whatever refund flow follows. They are defined now so those stages attach
 * to an existing vocabulary rather than inventing a parallel one, and so the
 * audit trail is complete from the first movement.
 */
enum InventoryTransactionType: string
{
    /** The first stock recorded against a product. */
    case InitialStock = 'initial_stock';

    /** Further stock received. */
    case Restock = 'restock';

    /** A correction made by an administrator, in either direction. */
    case ManualAdjustment = 'manual_adjustment';

    /** Stock set aside for an in-progress purchase. Not yet used. */
    case Reservation = 'reservation';

    /** A reservation given back because the purchase did not complete. Not yet used. */
    case Release = 'release';

    /** Stock that left because it was sold. Not yet used. */
    case Sale = 'sale';

    /** Stock that came back from a customer. Not yet used. */
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::InitialStock => 'Initial stock',
            self::Restock => 'Restock',
            self::ManualAdjustment => 'Manual adjustment',
            self::Reservation => 'Reserved',
            self::Release => 'Reservation released',
            self::Sale => 'Sold',
            self::Return => 'Returned',
        };
    }

    /**
     * Whether an administrator may post this type by hand.
     *
     * Sales, reservations and releases are consequences of a customer action
     * and belong to the flow that causes them. Letting staff post one
     * directly would put stock out of step with the orders it is meant to
     * reflect.
     */
    public function isManuallyPostable(): bool
    {
        return match ($this) {
            self::InitialStock, self::Restock, self::ManualAdjustment, self::Return => true,
            self::Reservation, self::Release, self::Sale => false,
        };
    }

    /**
     * Whether this type moves reserved stock rather than stock on hand.
     *
     * A reservation does not remove stock from the building -- it marks it as
     * spoken for. Only a sale actually takes it away.
     */
    public function affectsReservedStock(): bool
    {
        return $this === self::Reservation || $this === self::Release;
    }

    /**
     * The direction this type is allowed to move stock in.
     *
     * Null means either, which only a manual adjustment is permitted.
     */
    public function requiredSign(): ?int
    {
        return match ($this) {
            self::InitialStock, self::Restock, self::Return, self::Release => 1,
            self::Sale, self::Reservation => -1,
            self::ManualAdjustment => null,
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::InitialStock, self::Restock, self::Return, self::Release => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            self::Sale => 'bg-brand-50 text-brand-800 ring-brand-200',
            self::Reservation => 'bg-accent-50 text-accent-900 ring-accent-200',
            self::ManualAdjustment => 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    }
}
