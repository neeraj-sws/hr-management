@if(session('import_result') && (session('import_result')['kind'] ?? null) === ($kind ?? null))
    @php($r = session('import_result'))
    @php($tone = (($r['created'] ?? 0) + ($r['updated'] ?? 0)) === 0 ? (($r['kept'] ?? 0) > 0 && empty($r['errors']) ? 'alert-info' : 'alert-error') : (($r['skipped'] ?? 0) > 0 ? 'alert-info' : 'alert-success'))
    <div class="alert {{ $tone }}" role="status">
        <strong>{{ $r['kind'] }} import:</strong>
        @if(($r['rows'] ?? 0) > 0){{ $r['rows'] }} row(s) read — @endif
        {{ $r['created'] ?? 0 }} added, {{ $r['updated'] ?? 0 }} updated, {{ $r['kept'] ?? 0 }} already existed (kept), {{ $r['skipped'] ?? 0 }} skipped with errors.
        @if(! empty($r['errors']))
            <ul>
                @foreach($r['errors'] as $line)<li class="small">{{ $line }}</li>@endforeach
                @if(($r['more_errors'] ?? 0) > 0)<li class="small">…and {{ $r['more_errors'] }} more.</li>@endif
            </ul>
        @endif
        @if(! empty($r['notes']))
            <details><summary class="small">Records kept as they were ({{ count($r['notes']) }})</summary><ul>@foreach($r['notes'] as $line)<li class="small">{{ $line }}</li>@endforeach</ul></details>
        @endif
    </div>
@endif
