@props(['active' => false])

<a {{ $attributes->merge([
    'class' => 'rounded-md px-3 py-2 text-sm font-medium transition '
             . ($active
                 ? 'bg-brand-50 text-brand-800'
                 : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'),
]) }}>
    {{ $slot }}
</a>
