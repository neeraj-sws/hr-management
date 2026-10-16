@if(session('import_result') && (session('import_result')['kind'] ?? null) === ($kind ?? null))
    @php($r = session('import_result'))
    @php($tone = (($r['created'] ?? 0) + ($r['updated'] ?? 0)) === 0 ? (($r['kept'] ?? 0) > 0 && empty($r['errors']) ? 'alert-info' : 'alert-error') : (($r['skipped'] ?? 0) > 0 ? 'alert-info' : 'alert-success'))
    <div class="alert {{ $tone }}" role="status">
        <strong>{{ $r['kind'] }} import:</strong>
        @if(($r['rows'] ?? 0) > 0){{ $r['rows'] }} row(s) read — @endif
        {{ $r['created'] ?? 0 }} added, {{ $r['updated'] ?? 0 }} updated, {{ $r['kept'] ?? 0 }} already existed (kept), {{ $r['skipped'] ?? 0 }} skipped with errors.
        @if(! empty($r['legacy_link']))<div class="small" style="margin-top:4px"><a href="{{ route('payroll.legacy') }}">Open Legacy Salary →</a></div>@endif
        @if(! empty($r['months']))<div class="small" style="margin-top:4px">Months: <strong>{{ collect($r['months'])->map(fn ($m) => \Illuminate\Support\Carbon::createFromFormat('Y-m', $m)->format('M Y'))->join(', ') }}</strong>@if(isset($r['matched'])) · linked to employees: {{ $r['matched']['code'] + $r['matched']['name'] }} row(s) · names not in HRMS (not imported): {{ $r['matched']['none'] }}@endif</div>@endif
        @if(! empty($r['imported']))<div class="small" style="margin-top:4px">The imported days are listed below: @if(count($r['imported']['employee_ids']) === 1)<strong>{{ \App\Models\AttendanceEmployee::find($r['imported']['employee_ids'][0])?->name }}</strong>, @endif {{ \Illuminate\Support\Carbon::parse($r['imported']['from'])->format('d M Y') }} – {{ \Illuminate\Support\Carbon::parse($r['imported']['to'])->format('d M Y') }}. They also show in the employee's My Attendance, Attendance report and payroll.</div>@endif
        @if(! empty($r['errors']))
            <ul>
                @foreach($r['errors'] as $line)<li class="small">{{ $line }}</li>@endforeach
                @if(($r['more_errors'] ?? 0) > 0)<li class="small">…and {{ $r['more_errors'] }} more.</li>@endif
            </ul>
        @endif
        @if(! empty($r['pending']))
            <form method="POST" action="{{ route('attendance.records.import-for') }}" class="toolbar" style="border:0;padding:10px 0 4px;margin-top:8px;border-top:1px dashed var(--line)" x-data="{ busy: false }" x-on:submit="busy = true">
                @csrf
                <input type="hidden" name="token" value="{{ $r['pending']['token'] }}">
                <input type="hidden" name="name" value="{{ $r['pending']['name'] }}">
                @if($r['pending']['update_existing'])<input type="hidden" name="update_existing" value="1">@endif@if(! empty($r['pending']['into_locked']))<input type="hidden" name="into_locked" value="1">@endif
                <label class="field grow"><span><strong>Whose sheet is "{{ $r['pending']['name'] }}"?</strong> Pick the employee — all rows (Employee ID "{{ $r['pending']['code'] }}") are imported for them, no need to upload again.</span>
                    <select name="employee_id" required><option value="">Select employee</option>@foreach(\App\Models\AttendanceEmployee::where('is_active', true)->orderByRaw('LENGTH(employee_code)')->orderBy('employee_code')->get(['id', 'name', 'employee_code']) as $person)<option value="{{ $person->id }}">{{ $person->name }} ({{ $person->employee_code }})</option>@endforeach</select></label>
                <button class="btn btn-primary" type="submit" x-bind:disabled="busy"><span x-show="busy" class="spinner"></span>Import for this employee</button>
            </form>
        @endif
        @if(! empty($r['notes']))
            <details><summary class="small">Notes ({{ count($r['notes']) }})</summary><ul>@foreach($r['notes'] as $line)<li class="small">{{ $line }}</li>@endforeach</ul></details>
        @endif
    </div>
@endif
