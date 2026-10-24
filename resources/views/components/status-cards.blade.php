@props(['items' => [], 'label' => 'Filter'])
{{-- Status cards: a count with a coloured dot and a label; clicking one filters the list.
     items: [['label' => 'Active', 'count' => 12, 'tone' => 'green', 'active' => true, 'click' => "\$set('status','active')"], ...]
     tones: navy, blue, green, amber, red, gray, violet --}}
<div {{ $attributes->merge(['class' => 'status-cards']) }} role="group" aria-label="{{ $label }}">
    @foreach($items as $item)
        <button type="button" @class(['status-card', 'is-active' => ! empty($item['active'])]) wire:click="{{ $item['click'] }}" aria-pressed="{{ ! empty($item['active']) ? 'true' : 'false' }}">
            <strong>{{ $item['count'] }}</strong>
            <span><i class="status-dot status-dot-{{ $item['tone'] ?? 'gray' }}" aria-hidden="true"></i>{{ $item['label'] }}</span>
        </button>
    @endforeach
</div>
