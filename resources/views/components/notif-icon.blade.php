@props(['item'])
{{-- Type icon: light background, dark icon (colours from App\Support\NotificationPresenter::TYPES). --}}
<span {{ $attributes->merge(['class' => 'nf-icon']) }} style="background:{{ $item['bg'] }};color:{{ $item['fg'] }}" aria-hidden="true"><x-icon :name="$item['icon']" class="icon-sm" /></span>
