<x-admin.shell>

    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <x-page-header
            class="mb-0"
            title="Product images"
            description="Up to 8 images per product. The first image is the featured one shown on product cards and in the auction hall." />

        <x-button href="{{ route('admin.products') }}" wire:navigate variant="secondary" class="shrink-0">
            Back to products
        </x-button>
    </div>

    @if (session('status'))
        <x-alert variant="success" class="mb-6">{{ session('status') }}</x-alert>
    @endif

    @error('gallery')
        <x-alert variant="danger" class="mb-6">{{ $message }}</x-alert>
    @enderror

    <x-card title="Product" class="mb-6">
        <div class="flex flex-wrap items-center gap-4">
            @if ($product->image())
                <img src="{{ $product->image() }}" alt="" class="h-16 w-16 rounded-lg object-cover ring-1 ring-slate-200">
            @endif
            <div>
                <p class="font-semibold text-slate-900">{{ $product->name }}</p>
                <p class="text-xs text-slate-500">{{ $product->sku }}</p>
            </div>
        </div>
    </x-card>

    <x-card title="Upload images" class="mb-6">
        <form wire:submit="uploadImages" class="space-y-4">
            <p class="text-sm text-slate-600">
                JPEG, PNG, WebP or GIF, up to 5 MB each. Images are shown publicly once uploaded.
            </p>

            <input type="file" wire:model="upload" multiple accept="image/jpeg,image/png,image/webp,image/gif"
                   aria-label="Choose product images"
                   class="block w-full text-sm text-slate-700 file:mr-4 file:rounded-lg file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-brand-800 hover:file:bg-brand-100">

            @error('upload')
                <x-alert variant="danger" role="alert">{{ $message }}</x-alert>
            @enderror

            <div class="flex justify-end">
                <x-button type="submit" variant="primary" wire:loading.attr="disabled">Upload images</x-button>
            </div>
        </form>
    </x-card>

    <x-card title="Images" :padded="false">
        @if ($images->isNotEmpty())
            <div class="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($images as $image)
                    <div class="flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                        <div class="relative flex aspect-square items-center justify-center overflow-hidden bg-slate-100">
                            <img src="{{ $image->url() }}" alt="" class="h-full w-full object-cover">

                            @if ($image->position === 0)
                                <span class="absolute left-2 top-2 rounded-full bg-brand-700 px-2 py-0.5 text-xs font-semibold text-white">
                                    Featured
                                </span>
                            @endif
                        </div>

                        <div class="flex flex-col gap-2 p-3">
                            <p class="text-xs text-slate-500">Position {{ $image->position + 1 }}</p>

                            <div class="flex flex-wrap gap-1.5">
                                @if ($image->position !== 0)
                                    <x-button wire:click="setFeatured({{ $image->id }})" variant="secondary" size="sm">
                                        Make featured
                                    </x-button>
                                @endif

                                @if ($image->position > 0)
                                    <x-button wire:click="moveUp({{ $image->id }})" variant="ghost" size="sm">Back</x-button>
                                @endif

                                @if ($image->position < $images->count() - 1)
                                    <x-button wire:click="moveDown({{ $image->id }})" variant="ghost" size="sm">Forward</x-button>
                                @endif

                                <x-button wire:click="remove({{ $image->id }})"
                                          wire:confirm="Remove this image? It will be deleted permanently."
                                          variant="ghost" size="sm">Remove</x-button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <x-empty-state
                title="No images"
                description="Upload images to show them on product pages, product cards and the auction hall." />
        @endif
    </x-card>
</x-admin.shell>