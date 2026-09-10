<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsRepository;
use App\Domain\Shared\Money\Money;
use App\Enums\SettingType;
use App\Models\Setting;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->settings = app(SettingsRepository::class);
});

// -------------------------------------------------------------- Basic CRUD

it('reads a seeded setting', function (): void {
    seedSettings();

    expect($this->settings->getString('site_name'))->toBe('As-Is-Commerce')
        ->and($this->settings->getString('currency'))->toBe('GHS');
});

it('updates a setting', function (): void {
    seedSettings();

    $this->settings->set('site_name', 'Kwame Auctions');

    expect($this->settings->getString('site_name'))->toBe('Kwame Auctions')
        ->and(Setting::firstWhere('key', 'site_name')?->value)->toBe('Kwame Auctions');
});

it('refuses to write a setting that was never defined', function (): void {
    expect(fn (): Setting => $this->settings->set('not_a_real_setting', 'x'))
        ->toThrow(ModelNotFoundException::class);
});

it('reports whether a setting exists', function (): void {
    seedSettings();

    expect($this->settings->has('site_name'))->toBeTrue()
        ->and($this->settings->has('nope'))->toBeFalse();
});

// ---------------------------------------------------------- Typed accessors

it('returns a typed string', function (): void {
    Setting::factory()->create(['key' => 'tone', 'value' => 'premium', 'type' => SettingType::String]);

    expect($this->settings->getString('tone'))->toBe('premium')->toBeString();
});

it('returns a typed integer rather than a numeric string', function (): void {
    Setting::factory()->create(['key' => 'page_size', 'value' => '25', 'type' => SettingType::Integer]);

    expect($this->settings->getInt('page_size'))->toBe(25)->toBeInt();
});

it('returns a typed boolean', function (string $stored, bool $expected): void {
    Setting::factory()->create(['key' => 'flag', 'value' => $stored, 'type' => SettingType::Boolean]);

    expect($this->settings->getBool('flag'))->toBe($expected);
})->with([
    'one' => ['1', true],
    'zero' => ['0', false],
    'true' => ['true', true],
    'false' => ['false', false],
    'yes' => ['yes', true],
    'no' => ['no', false],
]);

/*
 * The specific bug this prevents: a boolean read back as the string "0",
 * which is truthy in a naive check and silently inverts the setting.
 */
it('does not treat the stored string zero as true', function (): void {
    Setting::factory()->create(['key' => 'flag', 'value' => '0', 'type' => SettingType::Boolean]);

    expect($this->settings->getBool('flag'))->toBeFalse();
});

it('returns money as an exact Money object', function (): void {
    Setting::factory()->create(['key' => 'fee', 'value' => '550000', 'type' => SettingType::Money]);

    $fee = $this->settings->getMoney('fee');

    expect($fee)->toBeInstanceOf(Money::class)
        ->and($fee?->minor)->toBe(550_000)
        ->and($fee?->toDecimalString())->toBe('5500.00')
        ->and($fee?->currency)->toBe('GHS');
});

it('stores money as integer minor units', function (): void {
    Setting::factory()->create(['key' => 'fee', 'value' => '0', 'type' => SettingType::Money]);

    $this->settings->set('fee', Money::fromDecimalString('100.00'));

    expect(Setting::firstWhere('key', 'fee')?->value)->toBe('10000');
});

// ------------------------------------------------------------ Missing keys

it('returns null for a missing setting', function (): void {
    expect($this->settings->get('absent'))->toBeNull()
        ->and($this->settings->getString('absent'))->toBeNull()
        ->and($this->settings->getInt('absent'))->toBeNull()
        ->and($this->settings->getMoney('absent'))->toBeNull();
});

it('returns the supplied default for a missing setting', function (): void {
    expect($this->settings->getString('absent', 'fallback'))->toBe('fallback')
        ->and($this->settings->getInt('absent', 7))->toBe(7)
        ->and($this->settings->getBool('absent', true))->toBeTrue();
});

it('returns the default when a setting exists but has no value', function (): void {
    seedSettings();

    expect($this->settings->getString('support_phone'))->toBeNull()
        ->and($this->settings->getString('support_phone', '0244000000'))->toBe('0244000000');
});

// ------------------------------------------------------------------- Cache

it('serves repeated reads from one query', function (): void {
    seedSettings();
    $this->settings->flush();

    DB::enableQueryLog();

    $this->settings->getString('site_name');
    $this->settings->getString('currency');
    $this->settings->getString('currency_symbol');
    $this->settings->getString('display_timezone');

    // One query builds the snapshot; the rest are served from it. Counted
    // against the settings table itself, so the test stays true whichever
    // cache driver tests happen to run on -- the thing it is proving is that
    // four reads do not translate into four lookups of the settings table.
    $settingsReads = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'from `settings`'))
        ->count();

    expect($settingsReads)->toBe(1);

    DB::disableQueryLog();
});

it('reflects a write immediately after it happens', function (): void {
    seedSettings();

    expect($this->settings->getString('site_name'))->toBe('As-Is-Commerce');

    $this->settings->set('site_name', 'Renamed');

    // Stale cache here would mean the admin saves a change and does not see it.
    expect($this->settings->getString('site_name'))->toBe('Renamed');
});

it('rebuilds the snapshot after an explicit flush', function (): void {
    seedSettings();
    $this->settings->getString('site_name');

    // A write that bypasses the repository, as a console command might do.
    Setting::where('key', 'site_name')->update(['value' => 'Changed Directly']);

    $this->settings->flush();

    expect($this->settings->getString('site_name'))->toBe('Changed Directly');
});

// ------------------------------------------------------------------ Public

it('exposes only settings marked public', function (): void {
    Setting::factory()->public()->create(['key' => 'shown', 'value' => 'yes']);
    Setting::factory()->create(['key' => 'hidden', 'value' => 'secret']);

    $public = $this->settings->publicValues();

    expect($public)->toHaveKey('shown')
        ->and($public)->not->toHaveKey('hidden');
});

it('groups settings for the admin screen', function (): void {
    seedSettings();

    expect($this->settings->group('general'))->not->toBeEmpty()
        ->and($this->settings->group('contact'))->not->toBeEmpty();
});

it('is resolved as a singleton so the snapshot is shared', function (): void {
    expect(app(SettingsRepository::class))->toBe(app(SettingsRepository::class));
});

it('is reachable through the settings() helper', function (): void {
    seedSettings();

    expect(settings())->toBeInstanceOf(SettingsRepository::class)
        ->and(settings()->getString('currency'))->toBe('GHS');
});
