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
