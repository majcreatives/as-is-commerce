<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Settings\SettingsRepository;
use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Reference data: the application settings this stage introduces.
 *
 * Structure (key, type, group, label) is defined here; only the value is
 * editable from the admin screen. Re-running the seeder repairs structure
 * without overwriting values an administrator has since changed.
 */
class SettingsSeeder extends Seeder
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'site_name',
                'value' => 'As-Is-Commerce',
                'type' => SettingType::String,
                'group' => 'general',
                'label' => 'Site name',
                'description' => 'Shown in the browser title, navigation and footer.',
                'is_public' => true,
            ],
            [
                'key' => 'currency',
                'value' => 'GHS',
                'type' => SettingType::String,
                'group' => 'general',
                'label' => 'Currency',
                'description' => 'ISO 4217 code. All monetary values are stored in this currency\'s minor units.',
                'is_public' => true,
            ],
            [
                'key' => 'currency_symbol',
                'value' => 'GH₵',
                'type' => SettingType::String,
                'group' => 'general',
                'label' => 'Currency symbol',
                'description' => 'Used for display only. Never stored alongside a monetary amount.',
                'is_public' => true,
            ],
            [
                'key' => 'display_timezone',
                'value' => 'Africa/Accra',
                'type' => SettingType::String,
                'group' => 'general',
                'label' => 'Display timezone',
                'description' => 'Timezone used when showing times to users. '
                    .'The application always stores timestamps in UTC; this affects presentation only.',
                'is_public' => true,
            ],
            /*
             | Checkout. Both values start at zero or a plain operational
             | default: nothing here invents a business figure. An auction's
             | own frozen rules govern delivery, tax and the payment window for
             | anything bought through an auction; these apply only to a
             | product bought outright with no auction involved.
             */
            [
                'key' => 'checkout_hold_minutes',
                'value' => '30',
                'type' => SettingType::Integer,
                'group' => 'checkout',
                'label' => 'Checkout hold (minutes)',
                'description' => 'How long a Buy Now checkout holds a unit of stock while awaiting '
                    .'payment, when no auction is involved. An unpaid checkout expires after this '
                    .'and the stock goes back. An auction checkout uses the deadline frozen into '
                    .'that auction instead.',
                'is_public' => false,
            ],
            [
                'key' => 'delivery_fee_minor',
                'value' => '0',
                'type' => SettingType::Money,
                'group' => 'checkout',
                'label' => 'Delivery charge',
                'description' => 'Added to a Buy Now checkout when no auction is involved. Kept as a '
                    .'separate line so it is never folded into a product price. Auction checkouts '
                    .'use the delivery charge frozen into the auction.',
                'is_public' => false,
            ],
            [
                'key' => 'checkout_tax_bps',
                'value' => '0',
                'type' => SettingType::Integer,
                'group' => 'checkout',
                'label' => 'Tax rate (basis points)',
                'description' => 'Applied to a Buy Now checkout when no auction is involved. '
                    .'1000 = 10%. Zero by default: no tax rate is assumed, and the component exists '
                    .'so a real rate can be configured without restructuring a checkout.',
                'is_public' => false,
            ],
            [
                'key' => 'support_phone',
                'value' => null,
                'type' => SettingType::String,
                'group' => 'contact',
                'label' => 'Support phone number',
                'description' => 'Shown to users who need help. Leave blank to hide it.',
                'is_public' => true,
            ],
            [
                'key' => 'support_email',
                'value' => null,
                'type' => SettingType::String,
                'group' => 'contact',
                'label' => 'Support email address',
                'description' => 'Shown to users who need help. Leave blank to hide it.',
                'is_public' => true,
            ],
            [
                'key' => 'notification_badge_window_days',
                'value' => 30,
                'type' => SettingType::Integer,
                'group' => 'notifications',
                'label' => 'Notification badge window (days)',
                'description' => 'How recent an unread notification must be to count toward the '
                    .'badge on the menu. Older unread ones stay in the notification centre; they '
                    .'just stop counting. Zero means no window: every unread notification counts.',
                'is_public' => false,
            ],
            [
                'key' => 'referrals_enabled',
                'value' => false,
                'type' => SettingType::Boolean,
                'group' => 'referrals',
                'label' => 'Referral rewards enabled',
                'description' => 'Whether a qualifying referral grants the referrer credits. '
                    .'Switching this off stops new rewards and changes nothing already granted.',
                'is_public' => false,
            ],
            [
                'key' => 'referral_reward_credits',
                'value' => 0,
                'type' => SettingType::Integer,
                'group' => 'referrals',
                'label' => 'Referral reward (credits)',
                'description' => 'Credits granted to the referrer when a referred customer makes '
                    .'their first qualifying purchase. Snapshotted at the moment of issue, so '
                    .'changing it never revalues a reward already granted.',
                'is_public' => false,
            ],
            [
                'key' => 'referral_max_rewards_per_referrer',
                'value' => 0,
                'type' => SettingType::Integer,
                'group' => 'referrals',
                'label' => 'Maximum rewarded referrals per customer',
                'description' => 'A cap on how many referral rewards one customer may accumulate. '
                    .'Zero means no cap, stated explicitly rather than left unbounded by default.',
                'is_public' => false,
            ],
            [
                'key' => 'otp_enabled',
                'value' => true,
                'type' => SettingType::Boolean,
                'group' => 'security',
                'label' => 'One-time codes enabled',
                'description' => 'Whether one-time verification codes can be issued. When off, '
                    .'email verification and password reset fail explicitly instead of pretending '
                    .'a code was sent.',
                'is_public' => false,
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::definitions() as $definition) {
            $setting = Setting::firstOrNew(['key' => $definition['key']]);

            // Structural fields are always refreshed from the definition;
            // the value is seeded once and then belongs to the administrator.
            $setting->type = $definition['type'];
            $setting->group = $definition['group'];
            $setting->label = $definition['label'];
            $setting->description = $definition['description'];
            $setting->is_public = $definition['is_public'];

            if (! $setting->exists) {
                $setting->value = $definition['type']->serialize($definition['value']);
            }

            $setting->save();
        }

        app(SettingsRepository::class)->flush();
    }
}
