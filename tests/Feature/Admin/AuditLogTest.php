<?php

declare(strict_types=1);

use App\Livewire\Admin\Operations\AuditLog;
use App\Models\AuctionRuleset;
use App\Models\User;
use Livewire\Component;
use Livewire\Livewire;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

/*
 * The audit log screen.
 *
 * A window onto the activity log the platform has been writing since the
 * settings stage, not a second audit system. The tests that matter are the
 * ones proving it cannot alter what it shows.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();

    $this->admin = userWithRole('admin');
});

// --------------------------------------------------------------- Access

it('is reachable by an administrator', function (): void {
    $this->actingAs($this->admin)->get(route('admin.audit'))->assertOk();
});

it('is refused to a customer', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.audit'))
        ->assertForbidden();
});

it('is refused to staff without the permission', function (): void {
    Livewire::actingAs(staffWith(['orders.view']))
        ->test(AuditLog::class)
        ->assertForbidden();
});

// --------------------------------------------------------------- Reading

it('shows an administrative action that was actually recorded', function (): void {
    // A real logged change rather than a hand-written activity row: the point
    // is that this screen reads what the platform already writes.
    $ruleset = AuctionRuleset::factory()->create();
    $ruleset->update(['name' => 'Renamed for the audit test']);

    $entry = Activity::query()->latest('id')->first();

    expect($entry)->not->toBeNull();

    Livewire::actingAs($this->admin)
        ->test(AuditLog::class)
        ->assertOk()
        ->assertSee($entry->description);
});

it('renders an honest empty state when nothing has been recorded', function (): void {
    Activity::query()->delete();

    Livewire::actingAs($this->admin)
        ->test(AuditLog::class)
        ->assertOk()
        ->assertSee('Nothing recorded');
});

it('filters to one log', function (): void {
    $ruleset = AuctionRuleset::factory()->create();
    $ruleset->update(['name' => 'Filtered']);

    $entry = Activity::query()->whereNotNull('log_name')->latest('id')->first();

    expect($entry)->not->toBeNull();

    Livewire::actingAs($this->admin)
        ->test(AuditLog::class)
        ->set('log', 'a-log-nobody-writes-to')
        ->assertOk()
        ->assertSee('Nothing recorded')
        ->set('log', $entry->log_name)
        ->assertSee($entry->description);
});

// --------------------------------------------------------------- Safety

it('offers no way to edit or delete an entry', function (): void {
    $own = array_diff(
        get_class_methods(AuditLog::class),
        get_class_methods(Component::class),
        get_class_methods(new class extends Component
        {
            use WithPagination;
        }),
    );

    // Filtering only. An audit trail an administrator can tidy is not an
    // audit trail.
    expect(array_values($own))
        ->toEqualCanonicalizing(['mount', 'updated', 'clearFilters', 'render']);
});

it('has no bulk action of any kind', function (): void {
    AuctionRuleset::factory()->create()->update(['name' => 'Bulk check']);

    $component = Livewire::actingAs($this->admin)->test(AuditLog::class)->assertOk();

    foreach (['Delete selected', 'Clear log', 'Purge', 'Select all'] as $forbidden) {
        $component->assertDontSee($forbidden);
    }
});
