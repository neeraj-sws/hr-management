@props(['status' => null, 'tone' => null])
@php($key = $tone ?? str((string) $status)->lower()->replace([' ', '-'], '_'))
<span {{ $attributes->merge(['class' => 'badge badge-'.$key]) }}>{{ trim($slot) !== '' ? $slot : str((string) $status)->replace('_', ' ') }}</span>
