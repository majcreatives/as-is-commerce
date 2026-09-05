<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a refund was issued.
 *
 * A controlled code rather than free text, because this is the field anybody
 * later asking "what actually goes wrong on this platform, and how often"
 * will count. Free text cannot be counted, and a note saying "cust paid late
 * ??" tells a reader nothing a month afterwards. An administrator may add a
 * note alongside; the code is what the record is filed under.
 *
 * THE REASON DOES NOT CHANGE WHAT HAPPENS. It never alters the amount, the
 * eligibility or the provider call -- refunding GH₵100 for an inventory
 * conflict and GH₵100 for a late payment are the same financial act, filed
 * differently. Anything that made the money behave differently would belong in
 * the eligibility policy, where it could be reasoned about, rather than hidden
 * in a label.
 *
 * The first three are the cases Stages 7 and 8 deliberately left open: a
 * payment that succeeded and could not be delivered against.
 */
enum RefundReason: string
{
    /** Another legitimate transaction took the unit first. */
    case InventoryConflict = 'inventory_conflict';

    /** The payment landed after the checkout window had closed. */
    case PaymentAfterCheckoutExpiry = 'payment_after_checkout_expiry';

    /** The payment landed after the order had been cancelled. */
    case PaymentAfterCancellation = 'payment_after_cancellation';

    /** The customer was charged twice for the same thing. */
    case DuplicatePayment = 'duplicate_payment';

    /** A deliberate operational decision that does not fit the above. */
    case AdministrativeRecovery = 'administrative_recovery';

    /** Anything else. The note carries the detail. */
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::InventoryConflict => 'Item no longer available',
            self::PaymentAfterCheckoutExpiry => 'Paid after checkout expired',
            self::PaymentAfterCancellation => 'Paid after cancellation',
            self::DuplicatePayment => 'Duplicate payment',
            self::AdministrativeRecovery => 'Administrative recovery',
            self::Other => 'Other',
        };
    }

    /**
     * What the customer is told, in their own terms.
     *
     * Deliberately less specific than the internal label. A customer does not
     * need to know which concurrent transaction beat theirs, and telling them
     * would explain our plumbing rather than their order.
     */
    public function customerDescription(): string
    {
        return match ($this) {
            self::InventoryConflict => 'the item was no longer available',
            self::PaymentAfterCheckoutExpiry => 'the checkout had already closed when the payment arrived',
            self::PaymentAfterCancellation => 'the order had already been cancelled when the payment arrived',
            self::DuplicatePayment => 'the payment was a duplicate',
            self::AdministrativeRecovery, self::Other => 'the order could not be completed',
        };
    }

    /**
     * The reason that fits an order the platform could not deliver against.
     *
     * Offered as the default on the admin form so the common case is filed
     * correctly without anybody choosing. It is a suggestion, not a rule: the
     * administrator picks, and whatever they pick changes nothing financially.
     */
    public static function suggestedFor(OrderStatus $status): self
    {
        return match ($status) {
            OrderStatus::PaymentExpired => self::PaymentAfterCheckoutExpiry,
            OrderStatus::Cancelled => self::PaymentAfterCancellation,
            OrderStatus::Paid => self::InventoryConflict,
            default => self::AdministrativeRecovery,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
