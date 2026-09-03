@props(['type' => 'text', 'error' => false])

<input type="{{ $type }}"
    {{ $attributes->merge([
        'class' => 'block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm '
                 . 'ring-1 ring-inset placeholder:text-slate-400 '
                 . 'focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm '
                 . ($error ? 'ring-red-400' : 'ring-slate-300'),
    ]) }} />
