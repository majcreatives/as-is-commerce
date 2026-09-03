<?php

declare(strict_types=1);

use App\Domain\Shared\Money\Money;
use App\Livewire\Admin\Payments\PackageManager;
use App\Models\CreditPackage;
use App\Models\CreditPurchase;
use Database\Seeders\CreditPackageSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    $this->admin = userWithRole('admin');
    $this->customer = userWithRole('customer');
});

// ----------------------------------------------------------------- Seeding

it('seeds the starting packages with exact prices', function (): void {
    app(CreditPackageSeeder::class)->run();

    $starter = CreditPackage::firstWhere('slug', 'starter');
    $popular = CreditPackage::firstWhere('slug', 'popular');
    $pro = CreditPackage::firstWhere('slug', 'pro');

    // Prices are integer pesewas: GH 10.00 is 1000, not 10.0.
    expect($starter->credit_amount)->toBe(100)->toBeInt()
        ->and($starter->price_minor)->toBe(1_000)->toBeInt()
        ->and($popular->credit_amount)->toBe(500)
        ->and($popular->price_minor)->toBe(4_500)
        ->and($pro->credit_amount)->toBe(1_000)
        ->and($pro->price_minor)->toBe(8_000);
});

it('can be seeded repeatedly without duplicating packages', function (): void {
    app(CreditPackageSeeder::class)->run();
    app(CreditPackageSeeder::class)->run();

    expect(CreditPackage::count())->toBe(count(CreditPackageSeeder::definitions()));
});

it('does not overwrite a price an administrator has changed', function (): void {
    app(CreditPackageSeeder::class)->run();

    CreditPackage::where('slug', 'popular')->update(['price_minor' => 5_000]);

    app(CreditPackageSeeder::class)->run();

    expect(CreditPackage::firstWhere('slug', 'popular')->price_minor)->toBe(5_000);
});

// -------------------------------------------------------------- Money shape

it('stores the price as integer minor units', function (): void {
    $package = CreditPackage::factory()->priced(500, Money::fromDecimalString('45.00')->minor)->create();

    expect($package->price_minor)->toBe(4_500)
        ->and($package->price()->toDecimalString())->toBe('45.00');

    // Straight from the database, so no cast could be hiding a float.
    $raw = DB::table('credit_packages')->where('id', $package->id)->first();
    expect($raw->price_minor)->toEqual(4_500);
});

it('keeps credits and price as separate, unrelated numbers', function (): void {
    $package = CreditPackage::factory()->priced(500, 4_500)->create();

    // 500 credits costs GH 45.00. The two figures have no arithmetic
    // relationship, and nothing in the code derives one from the other.
    expect($package->credit_amount)->toBe(500)
        ->and($package->price_minor)->toBe(4_500);
});

// -------------------------------------------------------------- Constraints

it('rejects a package with no credits', function (): void {
    expect(fn () => CreditPackage::factory()->create(['credit_amount' => 0]))
        ->toThrow(QueryException::class);
});

it('rejects a package with no price', function (): void {
    expect(fn () => CreditPackage::factory()->create(['price_minor' => 0]))
        ->toThrow(QueryException::class);
});

it('rejects a duplicate slug', function (): void {
    CreditPackage::factory()->create(['slug' => 'starter']);

    expect(fn () => CreditPackage::factory()->create(['slug' => 'starter']))
        ->toThrow(QueryException::class);
});

it('refuses to delete a package that has been bought', function (): void {
    $package = CreditPackage::factory()->create();
    CreditPurchase::factory()->create(['credit_package_id' => $package->id]);

    expect(fn () => $package->delete())->toThrow(QueryException::class);
});

// ----------------------------------------------------------- Administration

it('creates a package inactive so it cannot go on sale by accident', function (): void {
    Livewire::actingAs($this->admin)
        ->test(PackageManager::class)
        ->call('create')
        ->set('name', 'Weekend Bundle')
        ->set('credit_amount', 250)
        ->set('price', '22.50')
        ->set('sort_order', 5)
        ->call('save')
        ->assertHasNoErrors();

    $package = CreditPackage::firstWhere('name', 'Weekend Bundle');

    expect($package)->not->toBeNull()
        ->and($package->is_active)->toBeFalse()
        ->and($package->credit_amount)->toBe(250)
        // Entered as "22.50", stored as whole pesewas.
        ->and($package->price_minor)->toBe(2_250)
        ->and($package->slug)->toBe('weekend-bundle');
});

it('rejects a price containing a currency symbol', function (): void {
    Livewire::actingAs($this->admin)
        ->test(PackageManager::class)
        ->call('create')
        ->set('name', 'Bad')
        ->set('credit_amount', 100)
        ->set('price', 'GHS 45')
        ->call('save')
        ->assertHasErrors('price');

    expect(CreditPackage::count())->toBe(0);
});

it('rejects a package with fewer than one credit', function (): void {
    Livewire::actingAs($this->admin)
        ->test(PackageManager::class)
        ->call('create')
        ->set('name', 'Empty')
        ->set('credit_amount', 0)
        ->set('price', '10.00')
        ->call('save')
        ->assertHasErrors('credit_amount');
});

it('activates and withdraws a package', function (): void {
    $package = CreditPackage::factory()->inactive()->create();

    Livewire::actingAs($this->admin)
        ->test(PackageManager::class)
        ->call('activate', $package->id);

    expect($package->fresh()->is_active)->toBeTrue();

    Livewire::actingAs($this->admin)
        ->test(PackageManager::class)
        ->call('archive', $package->id);

    expect($package->fresh()->is_active)->toBeFalse();
});

it('generates a unique slug when names collide', function (): void {
    CreditPackage::factory()->create(['slug' => 'starter-pack']);

    Livewire::actingAs($this->admin)
        ->test(PackageManager::class)
        ->call('create')
        ->set('name', 'Starter Pack')
        ->set('credit_amount', 100)
        ->set('price', '10.00')
        ->call('save')
        ->assertHasNoErrors();

    expect(CreditPackage::where('slug', 'starter-pack-2')->exists())->toBeTrue();
});

// ---------------------------------------------------------------- Purchasable

it('offers only active packages to customers', function (): void {
    CreditPackage::factory()->create(['name' => 'On Sale']);
    CreditPackage::factory()->inactive()->create(['name' => 'Withdrawn']);

    $purchasable = CreditPackage::query()->purchasable()->get();

    expect($purchasable)->toHaveCount(1)
        ->and($purchasable->first()->name)->toBe('On Sale');
});

it('orders packages for display', function (): void {
    CreditPackage::factory()->create(['name' => 'Third', 'sort_order' => 30]);
    CreditPackage::factory()->create(['name' => 'First', 'sort_order' => 10]);
    CreditPackage::factory()->create(['name' => 'Second', 'sort_order' => 20]);

    expect(CreditPackage::query()->purchasable()->pluck('name')->all())
        ->toBe(['First', 'Second', 'Third']);
});

// ----------------------------------------------------------- Authorization

it('forbids a customer from managing packages', function (): void {
    Livewire::actingAs($this->customer)->test(PackageManager::class)->assertForbidden();
});

it('forbids a customer from the package admin route', function (): void {
    $this->actingAs($this->customer)->get('/admin/credit-packages')->assertForbidden();
});

it('allows an admin into the package admin route', function (): void {
    $this->actingAs($this->admin)->get('/admin/credit-packages')->assertOk();
});
