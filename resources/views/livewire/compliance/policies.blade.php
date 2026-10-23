<div>
    <div class="page-head"><div><h1>Compliance</h1><p>Employer-configured rules. A value of 0 disables a rule. Assessments only create <em>pending</em> penalties — each needs approval before it reaches payroll.</p></div>
        <a class="btn" href="{{ route('compliance.penalties') }}"><x-icon name="alert" class="icon-sm" />Penalties</a></div>
    <x-errors />

    <section class="card mb">
        <div class="card-head"><div><h2>Run compliance assessment</h2><p>Checks late check-ins, unauthorized absences (no attendance and no approved leave on a workday) and overdue tasks. Re-running never duplicates penalties.</p></div></div>
        <form class="toolbar" wire:submit="assess" style="border:0">
            <label class="field"><span>From</span><input type="date" wire:model="assessFrom"></label>
            <label class="field"><span>To</span><input type="date" wire:model="assessTo" x-bind:min="$wire.assessFrom" max="{{ today()->toDateString() }}"></label>
            <button class="btn btn-primary" wire:loading.attr="disabled"><span wire:loading wire:target="assess" class="spinner"></span>Run assessment</button>
        </form>
    </section>

    <div class="grid grid-3 mb">
        @foreach($rules as $key => [$label, $fields])
            <section class="card">
                <div class="card-head"><div><h2>{{ $label }}</h2><p>Applies to everyone unless an employee override is set below.</p></div></div>
                @php($ruleSlot = \App\Livewire\Compliance\Policies::slot($key))
                <form class="card-body stack" wire:submit="saveRule('{{ $key }}')">
                    @foreach($fields as $field => $fieldLabel)
                        @if($field === 'salary_rate')
                            {{-- How much a minute costs: a fixed amount, or the employee's own salary per minute (salary ÷ working days ÷ hours ÷ 60). --}}
                            <x-field label="Deduction per minute" :name="'values.'.$ruleSlot.'.'.$field"><select wire:model.live="values.{{ $ruleSlot }}.{{ $field }}" @disabled(!$canConfigure)><option value="0">Fixed amount (below)</option><option value="1">Employee's salary per minute</option></select></x-field>
                        @elseif($field === 'auto_deduct')
                            {{-- A workday with no attendance and no leave request: deduct straight away, or let HR approve each one. --}}
                            <x-field label="When it happens" :name="'values.'.$ruleSlot.'.'.$field"><select wire:model="values.{{ $ruleSlot }}.{{ $field }}" @disabled(!$canConfigure)><option value="1">Deduct automatically (no HR approval)</option><option value="0">Wait for HR approval</option></select></x-field>
                            <p class="small muted" style="margin:0">Counts a scheduled workday with no check-in and no leave request (sent or approved). The employee is told; HR can still waive it.</p>
                        @elseif($field === 'amount_per_minute' && (string) ($values[$ruleSlot]['salary_rate'] ?? '0') === '1')
                            <p class="small muted" style="margin:0">Each minute costs the employee's salary per minute: monthly salary ÷ the month's working days ÷ hours in the working day ÷ 60 (e.g. 10,000 ÷ 25 ÷ 9 ÷ 60 = 0.74).</p>
                        @else
                        <x-field :label="$fieldLabel" :name="'values.'.$ruleSlot.'.'.$field"><input type="number" step="0.01" min="0" wire:model="values.{{ $ruleSlot }}.{{ $field }}" @disabled(!$canConfigure)></x-field>
                        @endif
                    @endforeach
                    @if($canConfigure)<div><button class="btn btn-primary btn-sm">Save rule</button></div>@endif
                </form>
            </section>
        @endforeach
    </div>

    <div class="grid grid-2">
        @if($canConfigure)
            <section class="card">
                <div class="card-head"><div><h2>Employee-specific rule</h2><p>Overrides the global rule for one employee from the chosen date.</p></div></div>
                <form class="card-body" wire:submit="saveOverride"><div class="form-grid">
                    <x-field label="Apply to" name="overrideScope"><select wire:model.live="overrideScope"><option value="employee">One employee</option><option value="team">A whole team</option></select></x-field>
                    @if($overrideScope === 'team')<x-field label="Team" name="overrideTeamId"><select wire:model="overrideTeamId"><option value="">Select</option>@foreach($teams as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></x-field>
                    @else<x-field label="Employee" name="overrideEmployeeId"><select wire:model="overrideEmployeeId"><option value="">Select</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></x-field>@endif
                    <x-field label="Rule" name="overrideKey"><select wire:model.live="overrideKey">@foreach($overridable as $key => [$label])<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                    @foreach($overridable[$overrideKey][1] as $field => $fieldLabel)<x-field :label="$fieldLabel" :name="'overrideValues.'.$field"><input type="number" step="0.01" min="0" wire:model="overrideValues.{{ $field }}"></x-field>@endforeach
                    <x-field label="Effective from" name="overrideFrom"><input type="date" wire:model="overrideFrom"></x-field>
                </div><div class="form-actions"><button class="btn btn-primary">Save override</button></div></form>
            </section>
        @endif
        <section class="card">
            <div class="card-head"><h2>Active overrides & rule history</h2></div>
            <div class="list">
                @foreach($teamOverrides as $t)<div class="list-row"><div><strong>Team {{ $t->team?->name }} · {{ $overridable[$t->rule_key][0] ?? $t->rule_key }}</strong><small>from {{ $t->effective_from?->format('d M Y') ?? 'always' }} · {{ collect($t->configuration)->map(fn ($v, $k) => str($k)->replace('_', ' ').': '.$v)->join(', ') }}</small></div><x-badge tone="blue">team</x-badge></div>@endforeach
                @foreach($overrides as $o)<div class="list-row"><div><strong>{{ $o->employee?->name }} · {{ $overridable[$o->rule_key][0] ?? $o->rule_key }}</strong><small>from {{ $o->effective_from?->format('d M Y') ?? 'always' }} · {{ collect($o->configuration)->map(fn ($v, $k) => str($k)->replace('_', ' ').': '.$v)->join(', ') }}</small></div><x-badge tone="violet">override</x-badge></div>@endforeach
                {{ $overrides->links() }}
                @foreach($history as $r)<div class="list-row"><div><strong>{{ $rules[$r->rule_key][0] ?? $r->rule_key }}</strong><small>from {{ $r->effective_from?->format('d M Y') ?? 'always' }} · {{ collect($r->configuration)->map(fn ($v, $k) => str($k)->replace('_', ' ').': '.$v)->join(', ') }}</small></div><x-badge tone="blue">global</x-badge></div>@endforeach
                @if($overrides->isEmpty() && $teamOverrides->isEmpty() && $history->isEmpty())<x-empty title="No compliance rules configured yet" icon="shield" />@endif
            </div>
        </section>
    </div>
</div>
