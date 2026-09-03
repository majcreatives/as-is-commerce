@props(['for' => null, 'optional' => false])

<label @if($for) for="{{ $for }}" @endif
       {{ $attributes->merge(['class' => 'block text-sm font-medium text-slate-700']) }}>
    {{ $slot }}
    @if ($optional)
        <span class="ml-1 font-normal text-slate-400">(optional)</span>
    @endif
</label>
