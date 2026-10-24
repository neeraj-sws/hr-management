@props(['item'])
{{-- Notification title: the person's name (and a time) in bold, the rest normal. --}}
<span {{ $attributes->merge(['class' => 'nf-title']) }}>@foreach($item['parts'] as $part)@if($part['bold'])<strong>{{ $part['text'] }}</strong>@else{{ $part['text'] }}@endif @endforeach</span>
