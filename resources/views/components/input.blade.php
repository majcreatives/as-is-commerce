@props(['type' => 'text', 'error' => false])

@php
    // The error is announced two ways, because one of them is not always
    // available. aria-invalid plus aria-describedby is the good one: the field
    // is marked invalid, and the message is read out when focus lands on it. It
    // needs the field's own id, because x-field derived the error's id from the
    // field name and the two are the same thing by convention across the app.
    //
    // Both keys are added only when there is a real message. Reporting a field
    // as invalid when it is valid is its own kind of wrong, and a
    // describedby pointing at an element that is not rendered is a dangling
    // reference assistive technology has to recover from.
    $defaults = [
        'class' => 'block w-full rounded-lg border-0 bg-white px-3 py-2.5 text-slate-900 shadow-sm '
                 . 'ring-1 ring-inset placeholder:text-slate-400 '
                 . 'focus:ring-2 focus:ring-inset focus:ring-brand-600 sm:text-sm '
                 . ($error ? 'ring-red-400' : 'ring-slate-300'),
    ];

    if ($error) {
        $defaults['aria-invalid'] = 'true';

        $controlId = $attributes->get('id');

        if ($controlId) {
            $defaults['aria-describedby'] = str_replace('.', '-', $controlId).'-error';
        }
    }
@endphp

<input type="{{ $type }}" {{ $attributes->merge($defaults) }} />
