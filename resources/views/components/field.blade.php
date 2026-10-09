@props(['label', 'name' => null, 'hint' => null, 'full' => false])
<label {{ $attributes->class(['field', 'full' => $full, 'has-error' => $name && $errors->has($name)]) }}>
    <span>{{ $label }} @if($hint)<span class="hint">· {{ $hint }}</span>@endif</span>
    {{ $slot }}
    @if($name) @error($name)<span class="error">{{ $message }}</span>@enderror @endif
</label>
