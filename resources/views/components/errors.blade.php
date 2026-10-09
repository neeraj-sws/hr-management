@props(['only' => null])
@php($messages = $only ? collect($only)->flatMap(fn ($key) => $errors->get($key))->all() : $errors->all())
@if($messages)
    <div class="alert alert-error" role="alert">
        @if(count($messages) === 1){{ $messages[0] }}@else<strong>Please fix the following:</strong><ul>@foreach($messages as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
    </div>
@endif
