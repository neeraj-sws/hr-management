{{-- The day on one bar: working green, break yellow, idle orange, meeting blue, off grey. --}}
<div class="tm-bar" role="img" aria-label="Day activity from {{ $timeline['start'] }} to {{ $timeline['end'] }}">
    @foreach($timeline['parts'] as $part)<span class="tm-seg tm-seg-{{ $part['type'] }}" style="left:{{ $part['left'] }}%;width:{{ max(0.4, $part['width']) }}%" title="{{ ucfirst($part['type']) }} {{ $part['from'] }} – {{ $part['to'] }}"></span>@endforeach
</div>
@if(! empty($scale))<div class="tm-scale"><span>{{ $timeline['start'] }}</span><span>{{ $timeline['end'] }}</span></div>@endif
