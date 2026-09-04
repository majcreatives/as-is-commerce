<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * A single product's public page.
 *
 * The lookup is scoped to publicly visible products rather than fetching by
 * slug and then checking, so a draft or archived listing returns 404 rather
 * than existing at a guessable URL that merely refuses to render.
 */
final class ProductDetailController extends Controller
{
    public function __invoke(string $slug): View
    {
        $product = Product::query()
            ->publiclyVisible()
            ->with(['brand', 'category.parent'])
            ->where('slug', $slug)
            ->firstOrFail();

        return view('pages.catalog.product', [
            'product' => $product,
            'related' => $this->related($product),
        ]);
    }

    /**
     * A few other products from the same category.
     *
     * @return Collection<int, Product>
     */
    private function related(Product $product): Collection
    {
        return Product::query()
            ->publiclyVisible()
            ->with('brand')
            ->where('category_id', $product->category_id)
            ->whereKeyNot($product->id)
            ->orderByDesc('published_at')
            ->limit(4)
            ->get();
    }
}
