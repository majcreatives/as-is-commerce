<div>
    <x-admin.nav />

    <x-page-header
        title="Settings"
        description="Application-wide configuration. Auction behaviour is configured separately, under Auction rulesets." />

    <div x-data="{ saved: false }"
         x-on:settings-saved.window="saved = true; setTimeout(() => saved = false, 3000)">

        <div x-show="saved" x-cloak class="mb-6">
            <x-alert variant="success">Settings saved.</x-alert>
        </div>

        @if (session('status'))
            <x-alert variant="success" class="mb-6">{{ session('status') }}</x-alert>
        @endif

        <form wire:submit="save" class="space-y-6">
            @foreach ($groups as $group => $settings)
                <x-card :title="ucfirst($group)">
                    <div class="space-y-5">
                        @foreach ($settings as $setting)
                            @php($key = 'values.'.$setting->key)

                            @if ($setting->type === \App\Enums\SettingType::Boolean)
                                <div class="space-y-1.5">
                                    <label class="flex items-start gap-3">
                                        <input type="checkbox" wire:model="{{ $key }}"
                                               class="mt-0.5 size-4 rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                                        <span>
                                            <span class="block text-sm font-medium text-slate-700">{{ $setting->label }}</span>
                                            @if ($setting->description)
                                                <span class="block text-xs text-slate-500">{{ $setting->description }}</span>
                                            @endif
                                        </span>
                                    </label>
                                    <x-error :messages="$errors->first($key)" />
                                </div>
                            @else
                                <x-field
                                    :label="$setting->label"
                                    :name="$setting->key"
                                    :hint="$setting->description"
                                    :error="$errors->first($key)">

                                    <x-input
                                        :id="$setting->key"
                                        :type="$setting->type === \App\Enums\SettingType::Integer ? 'number' : 'text'"
                                        wire:model="{{ $key }}"
                                        :error="$errors->has($key)" />
                                </x-field>
                            @endif
                        @endforeach
                    </div>
                </x-card>
            @endforeach

            @can('settings.update')
                <div class="flex justify-end">
                    <x-button type="submit" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="save">Save settings</span>
                        <span wire:loading wire:target="save">Saving&hellip;</span>
                    </x-button>
                </div>
            @else
                <x-alert variant="info">You have read-only access to settings.</x-alert>
            @endcan
        </form>
    </div>
</div>
