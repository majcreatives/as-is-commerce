<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a delivery attempt did not succeed.
 *
 * A controlled code rather than free text, because this is the field anybody
 * later asking "why do deliveries fail here, and how often" will count. Free
 * text cannot be counted, and a note reading "no answer, tried twice" tells a
 * reader nothing a month afterwards. A rider's note goes alongside; the code
 * is what the attempt is filed under.
 *
 * THE REASON DECIDES NOTHING FINANCIAL. It never triggers a refund, never
 * releases stock, never returns a credit, and never reopens an auction. A
 * package refused at the door and one that could not be found are the same
 * operational event filed differently -- what the platform owes the customer,
 * if anything, is a separate decision made by a person through the refund
 * workflow.
 *
 * `IncorrectAddress` is the one worth noticing: it is the reason that points
 * at something the platform can actually fix before trying again.
 */
enum DeliveryFailureReason: string
{
    /** Nobody was there to receive it. */
    case RecipientUnavailable = 'recipient_unavailable';

    /** The address could not be found, or was wrong. */
    case IncorrectAddress = 'incorrect_address';

    /** The recipient declined to take it. */
    case RecipientRefused = 'recipient_refused';

    /** The number on the delivery could not be reached. */
    case PhoneUnreachable = 'phone_unreachable';

    /** The area could not be served on this attempt. */
    case DeliveryAreaIssue = 'delivery_area_issue';

    /** The package was not fit to hand over. */
    case DamagedPackage = 'damaged_package';

    /** Anything else. The note carries the detail. */
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::RecipientUnavailable => 'Recipient unavailable',
            self::IncorrectAddress => 'Address incorrect or not found',
            self::RecipientRefused => 'Recipient refused delivery',
            self::PhoneUnreachable => 'Phone unreachable',
            self::DeliveryAreaIssue => 'Could not serve the area',
            self::DamagedPackage => 'Package damaged',
            self::Other => 'Other',
        };
    }

    /**
     * What the customer is told.
     *
     * Neutral, and never accusing. "You refused the package" is a thing to
     * take up with somebody, not a thing to put on their order page, and a
     * customer whose delivery failed for an internal reason should not be
     * told it was their fault.
     */
    public function customerDescription(): string
    {
        return match ($this) {
            self::RecipientUnavailable => 'we could not reach anyone at the delivery address',
            self::IncorrectAddress => 'we could not locate the delivery address',
            self::RecipientRefused => 'the delivery was not accepted',
            self::PhoneUnreachable => 'we could not reach the phone number on the order',
            self::DeliveryAreaIssue => 'we could not complete delivery to that area',
            self::DamagedPackage => 'the package was not in a condition to hand over',
            self::Other => 'the delivery could not be completed',
        };
    }

    /**
     * Whether this is worth checking the address over before trying again.
     *
     * Advisory only, shown to staff on the retry screen. It changes nothing
     * about what the retry is allowed to do.
     */
    public function suggestsAddressReview(): bool
    {
        return match ($this) {
            self::IncorrectAddress, self::PhoneUnreachable, self::DeliveryAreaIssue => true,
            self::RecipientUnavailable, self::RecipientRefused,
            self::DamagedPackage, self::Other => false,
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
