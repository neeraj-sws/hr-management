@props(['user' => null, 'employee' => null])
{{-- Master Admin / HR / Accounts / Manager / Team lead / Employee / Trainee, the same everywhere (App\Support\PersonBadges). --}}
<span {{ $attributes->merge(['class' => 'person-badges']) }}>@foreach(\App\Support\PersonBadges::for($user, $employee) as $b)<x-badge :tone="$b['tone']">{{ $b['label'] }}</x-badge>@endforeach</span>
