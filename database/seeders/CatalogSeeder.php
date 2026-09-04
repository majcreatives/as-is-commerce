<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Shared\Money\Money;
use App\Enums\CatalogStatus;
use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * A small representative catalog for development.
 *
 * The products are invented, and deliberately so: the names are generic
 * rather than real commercial models, and nothing here claims anything about
 * a real manufacturer's goods.
 *
 * Stock is seeded through {@see InventoryService} rather than written to the
 * column, so even the seed data has a complete audit trail and exercises the
 * same path production uses.
 *
 * Safe to run repeatedly: an existing SKU is left exactly as it is.
 */
class CatalogSeeder extends Seeder
{
    /**
     * @return list<array{slug: string, name: string, children: list<array{slug: string, name: string}>}>
     */
    public static function categoryTree(): array
    {
        return [
            [
                'slug' => 'electronics',
                'name' => 'Electronics',
                'children' => [
                    ['slug' => 'phones', 'name' => 'Phones'],
                    ['slug' => 'laptops', 'name' => 'Laptops'],
                    ['slug' => 'tablets', 'name' => 'Tablets'],
                ],
            ],
            [
                'slug' => 'accessories',
                'name' => 'Accessories',
                'children' => [
                    ['slug' => 'audio', 'name' => 'Audio'],
                    ['slug' => 'power-and-charging', 'name' => 'Power & Charging'],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function brandNames(): array
    {
        return ['Northline', 'Kwaku Audio', 'Volta Devices', 'Ashanti Tech'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function products(): array
    {
        return [
            [
                'sku' => 'PHN-NL-0001',
                'name' => 'Northline N7 Smartphone 128GB',
                'category' => 'phones',
                'brand' => 'Northline',
                'condition' => ProductCondition::New,
                'price' => '5500.00',
                'stock' => 4,
                'short' => 'A 6.5-inch smartphone with 128GB of storage.',
            ],
            [
                'sku' => 'PHN-VD-0002',
                'name' => 'Volta V3 Smartphone 64GB',
                'category' => 'phones',
                'brand' => 'Volta Devices',
                'condition' => ProductCondition::Refurbished,
                'price' => '1850.00',
                'stock' => 7,
                'short' => 'Restored and tested, with a 90-day workshop warranty.',
            ],
            [
                'sku' => 'LAP-AT-0003',
                'name' => 'Ashanti Forge 15 Gaming Laptop',
                'category' => 'laptops',
                'brand' => 'Ashanti Tech',
                'condition' => ProductCondition::New,
                'price' => '12750.00',
                'stock' => 2,
                'short' => 'A 15-inch gaming laptop with a dedicated graphics card.',
            ],
            [
                'sku' => 'LAP-NL-0004',
                'name' => 'Northline Work 14 Ultrabook',
                'category' => 'laptops',
                'brand' => 'Northline',
                'condition' => ProductCondition::Used,
                'price' => '3400.00',
                'stock' => 5,
                'short' => 'A light 14-inch laptop in good cosmetic condition.',
            ],
            [
                'sku' => 'TAB-VD-0005',
                'name' => 'Volta Slate 10 Tablet',
                'category' => 'tablets',
                'brand' => 'Volta Devices',
                'condition' => ProductCondition::New,
                'price' => '2200.00',
                'stock' => 9,
                'short' => 'A 10-inch tablet suited to reading and study.',
            ],
            [
                'sku' => 'AUD-KA-0006',
                'name' => 'Kwaku Studio Over-Ear Headphones',
                'category' => 'audio',
                'brand' => 'Kwaku Audio',
                'condition' => ProductCondition::New,
                'price' => '650.00',
                'stock' => 20,
                'short' => 'Closed-back headphones with a detachable cable.',
            ],
            [
                'sku' => 'PWR-GEN-0007',
                'name' => 'Fast Charger 65W USB-C',
                'category' => 'power-and-charging',
                'brand' => null,
                'condition' => ProductCondition::New,
                'price' => '180.00',
                'stock' => 0,
                'short' => 'A 65W charger with a two-metre braided cable.',
            ],
        ];
    }

    public function run(): void
    {
        $categories = $this->seedCategories();
        $brands = $this->seedBrands();

        $products = app(ProductService::class);
        $inventory = app(InventoryService::class);

        foreach (self::products() as $definition) {
            if (Product::where('sku', $definition['sku'])->exists()) {
                continue;
            }

            $product = $products->create([
                'sku' => $definition['sku'],
                'name' => $definition['name'],
                'short_description' => $definition['short'],
                'description' => $definition['short'].' Sold and dispatched by As-Is-Commerce.',
                'category_id' => $categories[$definition['category']],
                'brand_id' => $definition['brand'] === null ? null : $brands[$definition['brand']],
                'condition' => $definition['condition'],
                'buy_now_price_minor' => Money::fromDecimalString($definition['price'], 'GHS')->minor,
                'currency' => 'GHS',
            ]);

            if ($definition['stock'] > 0) {
                $inventory->initialStock($product, $definition['stock'], 'Opening stock for development catalog.');
            }

            // Publishing sets out-of-stock automatically where there is
            // nothing available, so the charger lands correctly without a
            // special case here.
            $products->transitionTo($product->fresh(), ProductStatus::Active);
        }
    }

    /**
     * @return array<string, int> slug => id
     */
    private function seedCategories(): array
    {
        $ids = [];
        $order = 0;

        foreach (self::categoryTree() as $root) {
            $parent = Category::firstOrCreate(
                ['slug' => $root['slug']],
                ['name' => $root['name'], 'status' => CatalogStatus::Active, 'sort_order' => $order += 10],
            );

            $ids[$root['slug']] = $parent->id;
            $childOrder = 0;

            foreach ($root['children'] as $child) {
                $node = Category::firstOrCreate(
                    ['slug' => $child['slug']],
                    [
                        'name' => $child['name'],
                        'parent_id' => $parent->id,
                        'status' => CatalogStatus::Active,
                        'sort_order' => $childOrder += 10,
                    ],
                );

                $ids[$child['slug']] = $node->id;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, int> name => id
     */
    private function seedBrands(): array
    {
        $ids = [];

        foreach (self::brandNames() as $name) {
            $brand = Brand::firstOrCreate(
                ['name' => $name],
                ['slug' => str($name)->slug()->value(), 'status' => CatalogStatus::Active],
            );

            $ids[$name] = $brand->id;
        }

        return $ids;
    }
}
