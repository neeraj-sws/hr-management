@props(['employee' => null, 'name' => null, 'size' => null])
{{-- The employee's photo when there is one, otherwise their initials. --}}
@php
    $label = $employee?->name ?? $name ?? '';
    $initials = $employee ? $employee->initials() : (collect(explode(' ', (string) $label))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->join('') ?: 'SW');
    $photo = $employee?->photoUrl();
@endphp
<span {{ $attributes->merge(['class' => 'avatar'.($size === 'lg' ? ' avatar-lg' : '').($photo ? ' avatar-photo' : '')]) }}>@if($photo)<img src="{{ $photo }}" alt="{{ $label }}" loading="lazy">@else{{ $initials }}@endif</span>
