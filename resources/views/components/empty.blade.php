@props(['title' => 'Nothing here yet', 'icon' => 'inbox'])
<div {{ $attributes->merge(['class' => 'empty']) }}>
    <x-icon :name="$icon" />
    <strong>{{ $title }}</strong>
    @if(trim($slot) !== '')<div class="small">{{ $slot }}</div>@endif
</div>
