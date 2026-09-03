@props(['classes' => 'bg-slate-100 text-slate-700 ring-slate-200'])

<span {{ $attributes->merge([
    'class' => 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset '.$classes,
]) }}>
    {{ $slot }}
</span>
