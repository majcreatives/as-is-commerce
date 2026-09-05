<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Enums\NotificationCategory;
use Illuminate\View\View;
use Livewire\Component;

/**
 * What a customer wants to be told about.
 *
 * ONLY OPTIONAL CATEGORIES APPEAR. Transactional notifications -- money that
 * moved, an obligation incurred, an order that cannot proceed -- have no
 * switch, because offering one that the platform then ignores would be worse
 * than offering none, and honouring it would mean silently withholding "we
 * have your payment and cannot fulfil your order".
 *
 * The screen says so rather than leaving somebody to notice.
 */
class NotificationPreferencesForm extends Component
{
    /** @var array<string, array{in_app: bool, email: bool}> */
    public array $preferences = [];

    public function mount(): void
    {
        $stored = auth()->user()->notificationPreferences();

        foreach (NotificationCategory::optionalCases() as $category) {
            $this->preferences[$category->value] = [
                'in_app' => $stored->inAppEnabled($category),
                'email' => $stored->emailEnabled($category),
            ];
        }
    }

    public function save(): void
    {
        $user = auth()->user();
        $preferences = $user->notificationPreferences();

        // Driven by the enum rather than by what the form posted, so a
        // fabricated category in the request reaches nothing.
        foreach (NotificationCategory::optionalCases() as $category) {
            $row = $this->preferences[$category->value] ?? [];

            $preferences = $preferences->with(
                $category,
                (bool) ($row['in_app'] ?? true),
                (bool) ($row['email'] ?? true),
            );
        }

        $user->notification_preferences = $preferences->toArray();
        $user->save();

        session()->flash('notification-preferences', 'Your notification preferences were saved.');
    }

    public function render(): View
    {
        return view('livewire.profile.notification-preferences-form', [
            'categories' => NotificationCategory::optionalCases(),
        ]);
    }
}
