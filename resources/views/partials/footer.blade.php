<footer class="border-t border-slate-200 bg-white">
    <x-container class="flex flex-col gap-4 py-8 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <x-logo />
            <p class="mt-2 max-w-sm text-sm text-slate-500">
                An e-commerce store for Ghana, with a gamified credit auction channel. Prices in GH&#8373;.
            </p>
        </div>

        <nav class="flex flex-wrap gap-x-5 gap-y-2 text-sm" aria-label="Footer">
            <a href="{{ route('products.index') }}" class="text-slate-600 hover:text-slate-900">Shop</a>
            <a href="{{ route('auctions.index') }}" class="text-slate-600 hover:text-slate-900">Auctions</a>
            <a href="{{ route('how-it-works') }}" class="text-slate-600 hover:text-slate-900">How It Works</a>
        </nav>
    </x-container>

    <div class="border-t border-slate-100 py-4">
        <x-container class="text-xs text-slate-500">
            &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
        </x-container>
    </div>
</footer>
