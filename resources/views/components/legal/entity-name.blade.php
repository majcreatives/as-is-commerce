{{--
    Renders the operator's registered legal entity name, or a visible draft
    marker when it has not been supplied.

    The marker is on purpose and is not cosmetic. A privacy notice that quietly
    renders a blank where Act 843 requires a controller's name looks published
    and compliant, which is worse than one that says it is unfinished: the
    first cannot be spotted by reading it, the second can. app:check-environment
    refuses to let a site with this marker be exposed, so a site carrying it is
    a site somebody deliberately has not finished.

    One component for both pages, so the notice and the terms cannot print
    different names for the same company.
--}}
@props(['identity' => null])

@php
    $identity ??= \App\Support\Legal\OperatorIdentity::fromSettings();
@endphp

@if ($identity->name() !== null)
    <strong class="text-slate-900">{{ $identity->name() }}</strong>
@else
    <strong class="text-amber-700">[REGISTERED LEGAL ENTITY — NOT YET SUPPLIED]</strong>
@endif
