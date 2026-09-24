@props(['error' => false])

{{-- A password field with a show/hide toggle. The toggle is pure presentation:
     it only switches the input type, and the value stays bound to the Livewire
     property exactly as before. Alpine ships with Livewire, so no extra JS. --}}
<div x-data="{ show: false }" class="relative">
    <input {{ $attributes->merge([
        'type' => 'password',
        'class' => 'block w-full rounded-lg border-0 bg-white px-3 py-2.5 pr-11 text-slate-900 shadow-sm '
                 . 'ring-1 ring-inset placeholder:text-slate-400 '
                 . 'focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm '
                 . ($error ? 'ring-red-400' : 'ring-slate-300'),
    ]) }}
    x-bind:type="show ? 'text' : 'password'" />

    <button type="button"
            x-on:click="show = ! show"
            x-bind:aria-pressed="show ? 'true' : 'false'"
            x-bind:aria-label="show ? 'Hide password' : 'Show password'"
            class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 transition hover:text-slate-600">
        <svg x-show="! show" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
             stroke-width="1.5" stroke="currentColor" class="size-5">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
        </svg>
        <svg x-show="show" style="display: none" xmlns="http://www.w3.org/2000/svg" fill="none"
             viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
        </svg>
    </button>
</div>