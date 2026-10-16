<div>
    <div class="page-head"><div><h1>Legacy Salary</h1><p>Month-wise salary from the old Excel salary sheet — stored exactly as written (formula results unrounded, nothing recalculated), so the history stays as it was paid.</p></div></div>

    <section class="card mb">
        <div class="card-head"><div><h2>Import the old salary sheet</h2><p>Upload the whole workbook (e.g. "Salary Sheet 2026-2027.xlsx"): every sheet named like "April-2026" is one month, columns A–AM as in the sheet. People are matched by name (or an optional "Employee ID" column).</p></div></div>
        <div class="card-body stack">
            <details>
                <summary class="small"><strong>Columns, data types and formulas</strong></summary>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Col</th><th>Header</th><th>Type</th><th>Formula in the sheet</th><th>Meaning</th></tr></thead>
                    <tbody>
                    @foreach($columns as $key => [$letter, $label, $type, $formula, $help])
                        <tr><td>{{ $letter }}</td><td style="text-align:left"><code>{{ trim($label) }}</code></td>
                            <td>{{ ['text' => 'Text', 'month' => 'YYYY-MM', 'number' => 'Number', 'days' => 'Decimal (0.5 steps)', 'amount' => 'Currency', 'date' => 'Date'][$type] }}</td>
                            <td><code>{{ $formula ? str_replace('{r}', '', $formula) : '—' }}</code></td>
                            <td class="small" style="text-align:left">{{ $help }}</td></tr>
                    @endforeach
                    </tbody>
                </table></div>
                <p class="small muted">Values are stored as written — the formulas are only shown for reference. Free-text lines under a row (e.g. "3000 paid 12 Oct") are saved as notes on that person's row. Only HRMS employees are imported: a name that is not on the Employees page (e.g. "Drinking Water") is listed and not saved — add the employee or an "Employee ID" column and import again.</p>
            </details>
            @include('partials.import-result', ['kind' => 'Legacy salary'])
            <form method="POST" action="{{ route('payroll.legacy.import') }}" enctype="multipart/form-data" class="toolbar" x-data="{ busy: false }" x-on:submit="busy = true">
                @csrf
                <input type="file" name="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
                <label class="check"><input type="checkbox" name="update_existing" value="1"> Replace rows already imported (same person and month)</label>
                <button class="btn btn-primary" type="submit" x-bind:disabled="busy"><span x-show="busy" class="spinner"></span>Import</button>
                <a class="btn btn-sm" href="{{ route('imports.template', 'legacy-salary') }}"><x-icon name="download" class="icon-sm" />Excel template</a>
                <a class="btn btn-sm" href="{{ route('imports.template', ['legacy-salary', 'format' => 'csv']) }}">Sample CSV</a>
            </form>
        </div>
    </section>

    <section class="card">
        <div class="toolbar">
            <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Name or Employee ID"></label>
            <label class="field"><span>Month</span><select wire:model.live="month"><option value="">All months</option>@foreach($months as $m)<option value="{{ $m }}">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $m)->format('M Y') }}</option>@endforeach</select></label>
            <label class="field"><span>Employee</span><select wire:model.live="employee"><option value="">Everyone</option>@foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->employee_code }})</option>@endforeach</select></label>
            <label class="field"><span>Status</span><select wire:model.live="status"><option value="">Any</option>@foreach($statuses as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach</select></label>
        </div>
        <div class="pill-row" style="padding:0 18px 12px"><span class="pill"><b>{{ $totals['rows'] }}</b> rows</span><span class="pill">Paid <b>₹{{ number_format($totals['paid'], 2) }}</b></span><span class="pill">Remaining <b>₹{{ number_format($totals['remains'], 2) }}</b></span>@if($totals['unlinked'])<span class="pill"><b>{{ $totals['unlinked'] }}</b> not linked to an employee</span>@endif</div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Month</th><th>Employee</th><th class="num">Basic</th><th class="num">Present / paid days</th><th class="num">Late min</th><th class="num">Deduction</th><th class="num">Salary before adj.</th><th class="num">Bonus / TDS</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Remains</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($records as $r)
                @php($money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2))
                <tr wire:key="lg-{{ $r->id }}">
                    <td class="nowrap">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $r->payroll_month)->format('M Y') }}</td>
                    <td><strong>{{ $r->name }}</strong><small>@if($r->employee){{ $r->employee->employee_code }}@else not in HRMS @endif @if($r->notes) · 📝 note @endif</small></td>
                    <td class="num">{{ $money($r->basic_salary) }}</td>
                    <td class="num">{{ $r->present_days !== null ? (float) $r->present_days : '—' }} / {{ $r->total_salary_paid_days !== null ? (float) $r->total_salary_paid_days : '—' }}</td>
                    <td class="num">{{ $r->late_coming_minutes !== null ? (float) $r->late_coming_minutes : '—' }}</td>
                    <td class="num">{{ $money($r->total_payment_deduction) }}</td>
                    <td class="num">{{ $money($r->salary_before_adjustment) }}</td>
                    <td class="num">{{ $money($r->increment_bonus) }} / {{ $money($r->tds) }}</td>
                    <td class="num">{{ $money($r->total_salary_with_bonus) }}</td>
                    <td class="num">{{ $money($r->salary_paid) }}</td>
                    <td class="num">@if((float) $r->salary_remains > 0.005)<strong style="color:var(--red)">{{ $money($r->salary_remains) }}</strong>@else {{ $money($r->salary_remains) }} @endif</td>
                    <td>@if($r->payment_status)<x-badge :status="str($r->payment_status)->lower()->replace(' ', '_')->value()">{{ $r->payment_status }}</x-badge>@endif @if($r->payment_date)<small>{{ $r->payment_date->format('d M Y') }}</small>@endif</td>
                    <td><button class="btn btn-sm" wire:click="open({{ $r->id }})">All columns</button></td>
                </tr>
            @empty
                <tr><td colspan="13"><x-empty title="No legacy salary rows yet" icon="file">Upload the old salary workbook above.</x-empty></td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $records->links() }}
    </section>

    @if($opened)
        <x-modal :title="$opened->name.' · '.\Illuminate\Support\Carbon::createFromFormat('Y-m', $opened->payroll_month)->format('F Y')" size="lg">
            <div class="modal-body">
                @if($opened->notes)<div class="alert alert-info" style="white-space:pre-line"><strong>Notes in the sheet:</strong> {{ $opened->notes }}</div>@endif
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Col</th><th>Column</th><th class="num">Value</th><th>Formula</th></tr></thead>
                    <tbody>
                    @foreach($columns as $key => [$letter, $label, $type, $formula])
                        @continue(in_array($key, ['payroll_month'], true))
                        @php($v = $opened->{$key})
                        <tr><td>{{ $letter }}</td><td style="text-align:left">{{ trim($label) }}</td>
                            <td class="num">@if($v === null || $v === '')—@elseif($type === 'amount'){{ number_format((float) $v, 2) }}@if(round((float) $v, 2) != (float) $v) <small class="muted" title="As stored">{{ rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.') }}</small>@endif @elseif($type === 'date'){{ \Illuminate\Support\Carbon::parse($v)->format('d M Y') }}@elseif(in_array($type, ['days', 'number'], true)){{ (float) $v }}@else{{ $v }}@endif</td>
                            <td><code class="small">{{ $formula ? str_replace('{r}', '', $formula) : '' }}</code></td></tr>
                    @endforeach
                    </tbody>
                </table></div>
                <p class="small muted">Imported {{ $opened->created_at?->format('d M Y, h:i A') }} by {{ $opened->importer?->name ?? '—' }} from {{ $opened->source_file }} — sheet "{{ $opened->source_sheet }}", row {{ $opened->source_row }}.</p>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Close</button></div>
        </x-modal>
    @endif
</div>
