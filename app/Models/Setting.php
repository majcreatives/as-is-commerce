<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Settings\SettingsRepository;
use App\Enums\SettingType;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A single application configuration value.
 *
 * Reached through {@see SettingsRepository} rather than
 * queried directly, so reads go through the cache and casting happens in one
 * place.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property SettingType $type
 * @property string $group
 * @property string $label
 * @property string|null $description
 * @property bool $is_public
 */
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory, LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'label',
        'description',
        'is_public',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SettingType::class,
            'is_public' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('setting')
            // Only the value is worth recording. Labels and descriptions are
            // presentation detail, and logging them adds noise to the trail
            // that matters: who changed which value, and to what.
            ->logOnly(['key', 'value'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getDescriptionForEvent(string $eventName): string
    {
        return "Setting [{$this->key}] was {$eventName}";
    }
}
