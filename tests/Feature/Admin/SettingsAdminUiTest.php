<?php

declare(strict_types=1);

use App\Livewire\Admin\Settings\ManageSettings;
use App\Models\Setting;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->admin = userWithRole('admin');
    seedSettings();
});

it('loads current values into the form', function (): void {
    Livewire::actingAs($this->admin)
        ->test(ManageSettings::class)
        ->assertOk()
        ->assertSet('values.site_name', 'As-Is-Commerce')
        ->assertSet('values.currency', 'GHS');
});

it('saves changed settings', function (): void {
    Livewire::actingAs($this->admin)
        ->test(ManageSettings::class)
        ->set('values.site_name', 'Kwame Auctions')
        ->set('values.support_email', 'help@example.test')
        ->call('save')
        ->assertHasNoErrors();

    expect(settings()->getString('site_name'))->toBe('Kwame Auctions')
        ->and(settings()->getString('support_email'))->toBe('help@example.test');
});

it('stores a cleared field as not configured rather than an empty string', function (): void {
    Livewire::actingAs($this->admin)
        ->test(ManageSettings::class)
        ->set('values.support_phone', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::firstWhere('key', 'support_phone')?->value)->toBeNull();
});

it('requires the settings the application depends on', function (string $field): void {
    Livewire::actingAs($this->admin)
        ->test(ManageSettings::class)
        ->set("values.{$field}", '')
        ->call('save')
        ->assertHasErrors("values.{$field}");
})->with(['site_name', 'currency', 'display_timezone']);

it('rejects a currency that is not a three-letter code', function (string $value): void {
    Livewire::actingAs($this->admin)
        ->test(ManageSettings::class)
        ->set('values.currency', $value)
        ->call('save')
        ->assertHasErrors('values.currency');

    expect(settings()->getString('currency'))->toBe('GHS');
})->with(['CEDIS', 'GH', '123']);

it('rejects an invalid timezone', function (): void {
    Livewire::actingAs($this->admin)
        ->test(ManageSettings::class)
        ->set('values.display_timezone', 'Mars/Olympus')
        ->call('save')
        ->assertHasErrors('values.display_timezone');
});

it('rejects a malformed support email', function (): void {
    Livewire::actingAs($this->admin)
        ->test(ManageSettings::class)
        ->set('values.support_email', 'not-an-email')
        ->call('save')
        ->assertHasErrors('values.support_email');
});

it('records who changed a setting and what it was before', function (): void {
    Livewire::actingAs($this->admin)
        ->test(ManageSettings::class)
        ->set('values.site_name', 'Renamed')
        ->call('save');

    $entry = Activity::where('log_name', 'setting')->where('event', 'updated')->latest('id')->first();

    // Model-event diffs live in attribute_changes; `properties` holds only
    // data attached manually at the call site.
    expect($entry)->not->toBeNull()
        ->and($entry?->causer_id)->toBe($this->admin->id)
        ->and($entry?->description)->toBe('Setting [site_name] was updated')
        ->and(data_get($entry?->attribute_changes, 'attributes.value'))->toBe('Renamed')
        ->and(data_get($entry?->attribute_changes, 'old.value'))->toBe('As-Is-Commerce');
});

it('does not log an entry when nothing actually changed', function (): void {
    $before = Activity::where('log_name', 'setting')->count();

    Livewire::actingAs($this->admin)
        ->test(ManageSettings::class)
        ->call('save');

    expect(Activity::where('log_name', 'setting')->count())->toBe($before);
});

it('forbids a customer', function (): void {
    Livewire::actingAs(userWithRole('customer'))
        ->test(ManageSettings::class)
        ->assertForbidden();
});
