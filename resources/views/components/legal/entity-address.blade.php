{{--
    Renders the operator's registered postal address, or a visible draft marker.

    Same reasoning as <x-legal.entity-name />: an address that renders as blank
    cannot be checked by a reader, so the gap has to be visible on its face.
--}}
@props(['identity' => null])

@php
    $identity ??= \App\Support\Legal\OperatorIdentity::fromSettings();
@endphp

@if ($identity->address() !== null)
    <strong class="text-slate-900">{{ $identity->address() }}</strong>
@else
    <strong class="text-amber-700">[REGISTERED ADDRESS — NOT YET SUPPLIED]</strong>
@endif
