<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Domain\Shared\Money\Money;
use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;

/**
 * Typed, cached access to application settings.
 *
 * Callers ask for the type they need -- getString(), getInt(), getBool(),
 * getMoney() -- so no part of the application repeats its own casting, and a
 * setting cannot quietly arrive somewhere as the string "0" where a boolean
 * false was meant.
 *
 * Every read is served from a single cached snapshot of the table rather than
 * one query per key, so a page rendering a dozen settings still costs one
 * lookup. The cache is reached through Laravel's cache contract, so moving
 * from the database store to Redis later is a configuration change and
 * touches no caller.
 */
class SettingsRepository
{
    public const CACHE_KEY = 'settings.snapshot';

    /**
     * Request-local copy, so repeated reads within one request do not even
     * hit the cache driver.
     *
     * @var array<string, array{value: string|null, type: string}>|null
     */
    private ?array $snapshot = null;

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    // ----------------------------------------------------------- Typed reads

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->snapshot()[$key] ?? null;

        if ($row === null) {
            return $default;
        }

        $type = SettingType::tryFrom($row['type']) ?? SettingType::String;

        return $type->cast($row['value'], $this->currency()) ?? $default;
    }

    public function getString(string $key, ?string $default = null): ?string
    {
        $value = $this->get($key);

        return $value === null ? $default : (string) ($value instanceof Money ? $value->minor : $value);
    }

    public function getInt(string $key, ?int $default = null): ?int
    {
        $value = $this->get($key);

        return match (true) {
            $value === null => $default,
            $value instanceof Money => $value->minor,
            is_bool($value) => (int) $value,
            default => (int) $value,
        };
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return is_bool($value)
            ? $value
            : (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default);
    }

    public function getMoney(string $key, ?Money $default = null): ?Money
    {
        $value = $this->get($key);

        return match (true) {
            $value instanceof Money => $value,
            $value === null => $default,
            default => Money::fromMinor((int) $value, $this->currency()),
        };
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->snapshot());
    }

    /**
     * The configured currency, read without recursing through get().
     */
    public function currency(): string
    {
        $row = $this->snapshot()['currency'] ?? null;
        $value = $row['value'] ?? null;

        return is_string($value) && $value !== '' ? strtoupper($value) : 'GHS';
    }

    // ---------------------------------------------------------------- Writes

    /**
     * Update an existing setting's value.
     *
     * Only the value changes: key, type, group and labels are structural and
     * are defined by the seeder that introduces the setting, not by whoever
     * happens to write to it.
     */
    public function set(string $key, mixed $value): Setting
    {
        $setting = Setting::where('key', $key)->firstOrFail();

        $setting->value = $setting->type->serialize($value);
        $setting->save();

        $this->flush();

        return $setting;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    // ----------------------------------------------------------- Collections

    /**
     * @return Collection<int, Setting>
     */
    public function group(string $group): Collection
    {
        return Setting::where('group', $group)->orderBy('id')->get();
    }

    /**
     * Settings marked safe for public display.
     *
     * @return array<string, mixed>
     */
    public function publicValues(): array
    {
        return Setting::where('is_public', true)
            ->get()
            ->mapWithKeys(fn (Setting $s): array => [
                $s->key => $s->type->cast($s->value, $this->currency()),
            ])
            ->all();
    }

    public function flush(): void
    {
        $this->snapshot = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    // ----------------------------------------------------------------- Cache

    /**
     * @return array<string, array{value: string|null, type: string}>
     */
    private function snapshot(): array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }

        /** @var array<string, array{value: string|null, type: string}> $cached */
        $cached = $this->cache->rememberForever(
            self::CACHE_KEY,
            // Plain arrays rather than models: the cached payload stays small
            // and does not need to survive a model definition changing.
            fn (): array => Setting::query()
                ->get(['key', 'value', 'type'])
                ->mapWithKeys(fn (Setting $s): array => [
                    $s->key => ['value' => $s->value, 'type' => $s->type->value],
                ])
                ->all(),
        );

        return $this->snapshot = $cached;
    }
}
