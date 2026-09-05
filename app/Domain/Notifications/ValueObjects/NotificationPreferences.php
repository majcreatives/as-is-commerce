<?php

declare(strict_types=1);

namespace App\Domain\Notifications\ValueObjects;

use App\Enums\NotificationCategory;
use App\Enums\NotificationType;

/**
 * What one customer wants to be told about.
 *
 * TRANSACTIONAL NOTIFICATIONS ARE NOT NEGOTIABLE, and this object is where
 * that is enforced rather than remembered. Anything about money that has
 * moved, an obligation incurred, or an order that cannot proceed is delivered
 * whatever the stored preferences say. A switch that silently stopped the
 * platform telling somebody it had taken their payment would not be a quieter
 * experience, it would be a misleading one.
 *
 * Everything else -- bidding activity, how an auction turned out -- a customer
 * may switch off, in-app or by email independently.
 *
 * ABSENT MEANS DEFAULT. A customer who has never opened the preferences screen
 * has a null column and gets every default, so nothing has to be backfilled
 * and no row has to exist before somebody can be notified.
 */
final readonly class NotificationPreferences
{
    /**
     * @param  array<string, array{in_app: bool, email: bool}>  $categories
     */
    private function __construct(
        private array $categories,
    ) {}

    /**
     * Everything on, which is what a new account gets.
     */
    public static function defaults(): self
    {
        $categories = [];

        foreach (NotificationCategory::optionalCases() as $category) {
            $categories[$category->value] = ['in_app' => true, 'email' => true];
        }

        return new self($categories);
    }

    /**
     * Rebuild from what was stored, ignoring anything unrecognised.
     *
     * A category removed from the application in a later version leaves rows
     * behind; those are skipped rather than allowed to break a page.
     *
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromArray(?array $stored): self
    {
        $defaults = self::defaults()->categories;

        if ($stored === null) {
            return new self($defaults);
        }

        foreach (NotificationCategory::optionalCases() as $category) {
            $row = $stored[$category->value] ?? null;

            if (! is_array($row)) {
                continue;
            }

            $defaults[$category->value] = [
                'in_app' => (bool) ($row['in_app'] ?? true),
                'email' => (bool) ($row['email'] ?? true),
            ];
        }

        return new self($defaults);
    }

    /**
     * Whether this customer should get an in-app notification of this type.
     */
    public function allowsInApp(NotificationType $type): bool
    {
        if ($type->isTransactional()) {
            return true;
        }

        return $this->categories[$type->category()->value]['in_app'] ?? true;
    }

    /**
     * Whether this customer should get an email about this type.
     *
     * Two conditions, and both must hold: the type has to be worth an email at
     * all -- one per bid on a busy auction is how an address gets marked as
     * spam -- and the customer has to want it.
     */
    public function allowsEmail(NotificationType $type): bool
    {
        if (! $type->warrantsEmail()) {
            return false;
        }

        if ($type->isTransactional()) {
            return true;
        }

        return $this->categories[$type->category()->value]['email'] ?? true;
    }

    public function inAppEnabled(NotificationCategory $category): bool
    {
        return $this->categories[$category->value]['in_app'] ?? true;
    }

    public function emailEnabled(NotificationCategory $category): bool
    {
        return $this->categories[$category->value]['email'] ?? true;
    }

    /**
     * A copy with one category changed.
     */
    public function with(NotificationCategory $category, bool $inApp, bool $email): self
    {
        if (! $category->isOptional()) {
            // Refusing quietly rather than throwing: a caller offering a
            // switch for something that has none is a UI mistake, and the
            // right outcome is that the setting simply does not take effect.
            return $this;
        }

        $categories = $this->categories;
        $categories[$category->value] = ['in_app' => $inApp, 'email' => $email];

        return new self($categories);
    }

    /**
     * @return array<string, array{in_app: bool, email: bool}>
     */
    public function toArray(): array
    {
        return $this->categories;
    }
}
