{{-- Upload form for a historical-data import. Expects: $type (salary|attendance), $kind (Salary|Attendance), $action (route URL). --}}
<div class="card-body stack">
    <details>
        <summary class="small"><strong>Columns and formats</strong> — download the Excel template, fill one row per record, save and upload.</summary>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Column</th><th>What to enter</th></tr></thead>
            <tbody>
            @foreach(\App\Services\ImportService::HELP[$type] as $column => $help)
                <tr><td><code>{{ $column }}</code>@if(in_array($column, $type === 'salary' ? \App\Services\ImportService::SALARY_REQUIRED : \App\Services\ImportService::ATTENDANCE_REQUIRED, true)) <x-badge tone="red">required</x-badge>@endif</td><td class="small">{{ $help }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
        <p class="small muted">Rows in a locked payroll period are refused. Each problem row is listed with its row number; the other rows are still imported.</p>
    </details>
    @include('partials.import-result', ['kind' => $kind])
    <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="toolbar" x-data="{ busy: false }" x-on:submit="busy = true">
        @csrf
        @if($type === 'attendance')
            <label class="field"><span>1. Import for employee <span class="hint">· choose for one person's monthly sheet</span></span><select name="employee_id" title="For one person's sheet: every row is theirs, the Employee ID column is ignored"><option value="">— Many employees: use the Employee ID column —</option>@foreach(\App\Models\AttendanceEmployee::where('is_active', true)->orderByRaw('LENGTH(employee_code)')->orderBy('employee_code')->get(['id', 'name', 'employee_code']) as $person)<option value="{{ $person->id }}">{{ $person->name }} ({{ $person->employee_code }})</option>@endforeach</select></label>
        @endif
        <input type="file" name="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
        <label class="check"><input type="checkbox" name="update_existing" value="1"> Update records that already exist</label>
        @if($type === 'attendance')@if(\App\Support\Access::isAdmin(auth()->user()))<label class="check" title="For migrating old history: days inside a locked payroll are saved too; that payroll is not recalculated. Admins only."><input type="checkbox" name="into_locked" value="1"> Old data: also import into locked payroll months</label>@endif@endif
        <button class="btn btn-primary" type="submit" x-bind:disabled="busy"><span x-show="busy" class="spinner"></span>Import</button>
        <a class="btn btn-sm" href="{{ route('imports.template', $type) }}"><x-icon name="download" class="icon-sm" />Excel template</a>
        <a class="btn btn-sm" href="{{ route('imports.template', [$type, 'format' => 'csv']) }}">{{ $type === 'attendance' ? 'Sample CSV' : 'CSV template' }}</a>
    </form>
    <span class="small muted">Existing records are kept unless you tick "Update records that already exist".</span>
</div>
