<div>
    <div class="page-head"><div><h1>Salary</h1><p>Effective-dated salary profiles drive payroll. Changes cannot back-date into a locked payroll period.</p></div>
        <button class="btn btn-primary" wire:click="newAdjustment"><x-icon name="plus" class="icon-sm" />{{ auth()->user()->can('salary.adjust') ? 'New adjustment' : 'Request adjustment' }}</button></div>
    <x-errors />

    @if($own)
        <section class="card mb">
            <div class="card-head"><div><h2>My salary</h2><p>Current and historical salary profiles</p></div><a class="btn btn-sm" href="{{ route('payroll.payslips') }}">My payslips</a></div>
            <div class="table-wrap"><table class="table"><thead><tr><th>Effective from</th><th class="num">Monthly salary</th><th class="num">Working days / month</th><th class="num">Hours / day</th></tr></thead><tbody>
                @forelse($myProfiles as $p)<tr><td>{{ $p->effective_from->format('d M Y') }} @if($loop->first)<x-badge status="active">current</x-badge>@endif</td><td class="num"><strong>{{ $p->isDaily() ? number_format((float) $p->daily_rate, 2) : number_format((float) $p->monthly_salary, 2) }}</strong>@if($p->isDaily())<small>per day</small>@endif</td><td class="num">{{ $p->working_days_per_month }}</td><td class="num">{{ (float) $p->standard_daily_hours }}</td></tr>
                @empty<tr><td colspan="4"><x-empty title="No salary profile configured" icon="wallet" /></td></tr>@endforelse
            </tbody></table></div>
        </section>
    @endif

    @if($canManage)
        <section class="card mb">
            <div class="card-head"><div><h2>Import salary records</h2><p>Add salary profiles (monthly salary from a date) from Excel (.xlsx) or CSV: one row per HRMS employee per effective date — the Employee ID must be an existing employee.</p></div><a class="btn btn-sm" href="{{ route('payroll.legacy') }}" title="The old month-wise salary sheet (April-2026, May-2026 …): deductions, paid and remaining amounts"><x-icon name="file" class="icon-sm" />Old month-wise salary sheet →</a></div>
            @include('partials.import-form', ['type' => 'salary', 'kind' => 'Salary', 'action' => route('payroll.salary.import')])
        </section>
    @endif

    @if($employees)
        <section class="card mb">
            <div class="card-head"><h2>Employee salaries</h2></div>
            <div class="toolbar"><label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Name or code"></label></div>
            <div class="table-wrap"><table class="table"><thead><tr><th>Employee</th><th>Department</th><th class="num">Monthly</th><th>Effective from</th><th class="num">Days/month</th><th class="num">Hours/day</th><th></th></tr></thead><tbody>
                @forelse($employees as $e)
                    <tr wire:key="s-{{ $e->id }}"><td><strong>{{ $e->name }}</strong><small>{{ $e->employee_code }}</small></td><td>{{ $e->department?->name ?? '—' }}</td>
                        <td class="num">@if($e->salaryProfile?->isDaily()){{ number_format((float) $e->salaryProfile->daily_rate, 2) }}<small>per day</small>@else{{ $e->salaryProfile ? number_format((float) $e->salaryProfile->monthly_salary, 2) : '—' }}@endif</td><td>{{ $e->salaryProfile?->effective_from?->format('d M Y') ?? '—' }}</td>
                        <td class="num">@if($e->salaryProfile)@php($sd = $salaryDays[$e->id] ?? null){{ $sd['days'] ?? $e->salaryProfile->working_days_per_month }}@if($sd['auto'] ?? false)<small title="Counted from the calendar: Sundays, the off Saturdays (e.g. 3rd Saturday) and unpaid holidays are left out. Changes every month.">auto · {{ $sd['month'] }}</small>@endif @else — @endif</td><td class="num">{{ $e->salaryProfile ? (float) $e->daily_work_hours : '—' }}</td>
                        <td>@if($canManage)@if($e->salaryProfile)<button type="button" class="btn btn-sm" wire:click="correctProfile({{ $e->id }})">Edit</button> @endif<button type="button" class="btn btn-sm" wire:click="editProfile({{ $e->id }})">Revise</button>@endif</td></tr>
                @empty<tr><td colspan="7"><x-empty title="No employees" icon="users" /></td></tr>@endforelse
            </tbody></table></div>
            {{ $employees->links() }}
        </section>
    @endif

    <section class="card">
        <div class="card-head"><div><h2>Salary adjustments</h2><p>Increments raise the monthly salary from the effective date (old salary stays in history). Bonus and deductions apply in the payroll covering their effective date — once, or every month when recurring. All need approval.</p></div></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Employee</th><th>Type</th><th class="num">Amount</th><th>Effective</th><th>Repeats</th><th>Reason</th><th>Status</th><th></th></tr></thead><tbody>
            @forelse($adjustments as $a)
                <tr><td>{{ $a->employee?->name }}</td><td><x-badge :tone="$a->isIncrement() ? 'blue' : ($a->isDeduction() ? 'red' : 'green')">{{ $a->label() }}</x-badge></td><td class="num">{{ $a->isDeduction() ? '−' : '+' }}{{ number_format((float) $a->amount, 2) }}</td><td>{{ $a->effective_on->format('d M Y') }}</td>
                    <td>@if($a->is_recurring)Monthly{{ $a->recurring_until ? ' until '.$a->recurring_until->format('M Y') : ', until stopped' }}@elseif($a->isIncrement())Salary revised @else Once @endif</td><td>{{ $a->reason }}</td>
                    <td><x-badge :status="$a->status" />@if($a->payroll_item_id)<small>in payroll</small>@endif</td>
                    <td>@if(in_array($a->id, $decidable))<div class="row-actions"><button class="btn btn-sm btn-success" wire:click="decide({{ $a->id }}, true)" wire:confirm="Approve this request?">Approve</button><button class="btn btn-sm btn-danger" wire:click="decide({{ $a->id }}, false)" wire:confirm="Reject this request?">Reject</button></div>@elseif($a->is_recurring && $a->status === 'approved' && (! $a->recurring_until || $a->recurring_until->gte(today()->startOfMonth())) && auth()->user()->canAny(['salary.adjust', 'payroll.manage']))<button class="btn btn-sm" wire:click="stopRecurring({{ $a->id }})" wire:confirm="Stop this recurring adjustment from this month's payroll on?">Stop</button>@endif</td></tr>
            @empty<tr><td colspan="8"><x-empty title="No adjustments" icon="receipt" /></td></tr>@endforelse
        </tbody></table></div>
    </section>

    @if($editing)
        <x-modal title="{{ $correcting ? 'Edit salary (correct) · ' : 'Revise salary · ' }}{{ $editing->name }}" size="lg">
            <form wire:submit="saveProfile"><div class="modal-body"><x-errors />@if($correcting)<p class="small muted" style="margin:-4px 0 10px">Fixing the current salary record in place. Keep the effective date to overwrite it, or change it to add a new revision.</p>@endif<div class="form-grid">
                <x-field label="Salary type" name="payBasis"><select wire:model.live="payBasis">@foreach(\App\Models\SalaryProfile::PAY_BASES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                @if($payBasis === 'daily')
                    <x-field label="Salary per day" name="dailyRate" hint="paid for each working day attended"><input type="number" step="0.01" min="0" wire:model="dailyRate" placeholder="130"></x-field>
                @else
                    <x-field label="Monthly salary" name="monthly"><input type="number" step="0.01" min="0" wire:model="monthly"></x-field>
                @endif
                <x-field label="Effective from" name="effectiveFrom"><input type="date" wire:model="effectiveFrom"></x-field>
                @if($autoDays)<div class="field"><span class="label">Working days per month</span><p class="small muted" style="margin:6px 0 0">Auto from the calendar — {{ $autoDays['month'] }} has <strong>{{ $autoDays['days'] }}</strong> working days. Sundays, the off Saturdays (e.g. 3rd Saturday) and unpaid holidays are left out, so it changes every month.</p></div>
                @else<x-field label="Working days per month" name="workingDays" hint="fixed — turn on auto in Settings > Payroll"><input type="number" min="1" max="31" wire:model="workingDays"></x-field>@endif
                <x-field label="Standard hours per day" name="dailyHours"><input type="number" step="0.25" min="0.25" max="24" wire:model="dailyHours"></x-field>
            </div>
            @if($payBasis !== 'daily')<div class="field mt"><span class="label">Salary structure <span class="muted small">— earnings must add up to the monthly salary; leave empty to use the company template</span></span>
                <div class="table-wrap"><table class="table"><thead><tr><th>Component</th><th>Type</th><th class="num">Monthly amount</th><th></th></tr></thead><tbody>
                    @forelse($components as $i => $c)
                        <tr wire:key="comp-{{ $i }}"><td><input class="input" wire:model="components.{{ $i }}.name" placeholder="Basic / HRA / Special allowance" aria-label="Component name">@error('components.'.$i.'.name')<span class="error">{{ $message }}</span>@enderror</td>
                            <td><select class="input" wire:model="components.{{ $i }}.type" aria-label="Component type"><option value="earning">Earning</option><option value="deduction">Fixed deduction</option></select></td>
                            <td class="num"><input class="input" type="number" step="0.01" min="0" wire:model="components.{{ $i }}.amount" aria-label="Amount">@error('components.'.$i.'.amount')<span class="error">{{ $message }}</span>@enderror</td>
                            <td><button type="button" class="btn btn-sm" wire:click="removeComponent({{ $i }})" aria-label="Remove">&times;</button></td></tr>
                    @empty
                        <tr><td colspan="4" class="muted small">No components — the company template (Settings → Payroll) or a single Basic line is used.</td></tr>
                    @endforelse
                </tbody></table></div>
                @error('components')<span class="error">{{ $message }}</span>@enderror
                <div class="toolbar mt"><button type="button" class="btn btn-sm" wire:click="addComponent('earning')">+ Earning</button><button type="button" class="btn btn-sm" wire:click="addComponent('deduction')">+ Fixed deduction</button><button type="button" class="btn btn-sm" wire:click="applyTemplate">Use company template</button>
                    <span class="small muted">Earnings total: {{ number_format(collect($components)->where('type', 'earning')->sum(fn ($c) => (float) $c['amount']), 2) }}</span></div>
            </div>@endif</div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Save profile</button></div></form>
        </x-modal>
    @endif
    @if($showAdjustment)
        <x-modal title="{{ auth()->user()->can('salary.adjust') ? 'New salary adjustment' : 'Request salary adjustment' }}">
            <form wire:submit="saveAdjustment"><div class="modal-body"><x-errors /><div class="form-grid">
                @can('salary.adjust')<x-field label="Employee" name="adjustmentEmployeeId" full><select wire:model="adjustmentEmployeeId"><option value="">Myself</option>@foreach($allEmployees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></x-field>@endcan
                <x-field label="Type" name="kind"><select wire:model.live="kind">@foreach(\App\Models\SalaryAdjustment::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></x-field>
                <x-field :label="$kind === 'increment' ? 'Increase in monthly salary' : 'Amount'" name="amount"><input type="number" step="0.01" min="0.01" wire:model="amount"></x-field>
                <x-field label="Effective date" name="effectiveOn"><input type="date" wire:model="effectiveOn"></x-field>
                <x-field label="Reason" name="reason"><input wire:model="reason"></x-field>
                @if($kind === 'increment')
                    <p class="small muted" style="grid-column:1/-1;margin:0">On approval a new salary revision starts on the effective date: current salary + this amount. Earlier salary history is kept.</p>
                @else
                    <label class="check" style="grid-column:1/-1"><input type="checkbox" wire:model.live="recurring"> Repeat every month (recurring)</label>
                    @if($recurring)<x-field label="Last month (optional)" name="recurringUntil" hint="leave empty to repeat until stopped"><input type="month" wire:model="recurringUntil"></x-field>@endif
                @endif
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Submit for approval</button></div></form>
        </x-modal>
    @endif
</div>
