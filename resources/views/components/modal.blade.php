@props(['title', 'close' => 'closeModal', 'size' => null])
<div class="modal-backdrop" role="dialog" aria-modal="true" aria-label="{{ $title }}">
    <div class="modal {{ $size === 'lg' ? 'modal-lg' : '' }}">
        <div class="modal-head">
            <h2>{{ $title }}</h2>
            <button type="button" class="btn btn-ghost btn-sm" wire:click="{{ $close }}" aria-label="Close"><x-icon name="x" /></button>
        </div>
        {{ $slot }}
    </div>
</div>
