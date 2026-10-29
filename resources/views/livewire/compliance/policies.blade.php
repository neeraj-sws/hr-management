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
                        @elseif($field === 'also_fine')
                            {{-- An absent day is already not paid; the fine is a second cut, so it is a choice. --}}
                            <x-field label="The absent day is already not paid" :name="'values.'.$ruleSlot.'.'.$field"><select wire:model="values.{{ $ruleSlot }}.{{ $field }}" @disabled(!$canConfigure)><option value="0">No fine: the unpaid day is enough (one cut)</option><option value="1">Also fine on top of the unpaid day (two cuts)</option></select></x-field>
                        @elseif($field === 'deduct_now')
                            {{-- Late fine: cut straight away (the employee can give a reason; HR accepting it removes the fine), or wait for HR. --}}
                            <x-field label="When a late fine is made" :name="'values.'.$ruleSlot.'.'.$field"><select wire:model="values.{{ $ruleSlot }}.{{ $field }}" @disabled(!$canConfigure)><option value="0">Wait for HR approval</option><option value="1">Deduct automatically (no HR approval)</option></select></x-field>
                            <p class="small muted" style="margin:0">The employee is told the date, check-in time and amount. They can give a reason on the Penalties page (Give reason); when HR or an admin accepts it, the fine is removed. Use "Employee-specific rules" below to fine only some people.</p>
                        @elseif($field === 'hours_cut')
                            {{-- On top of the fine, pay only the time worked: the minutes short of the working day are cut at the hourly rate. --}}
                            <x-field label="Also cut the hours not worked" :name="'values.'.$ruleSlot.'.'.$field"><select wire:model="values.{{ $ruleSlot }}.{{ $field }}" @disabled(!$canConfigure)><option value="0">No: only the late fine</option><option value="1">Yes: late fine + pay only the hours worked</option></select></x-field>
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
                <div class="card-head"><div><h2>Employee-specific rule</h2><p>Overrides the global rule for one employee, several employees or a team from the chosen date. Everyone else keeps the global rule.</p></div></div>
                <form class="card-body" wire:submit="saveOverride">
                    @if($editing)<div class="alert alert-info" style="display:flex;justify-content:space-between;align-items:center;gap:8px">Changing an existing rule: only its values and start date. <button type="button" class="btn btn-sm" wire:click="cancelEdit">Cancel</button></div>@endif
                    <div class="form-grid">
                    {{-- While a rule is being changed, who it is for and which rule stay as they are. --}}
                    <fieldset style="display:contents" @disabled($editing)>
                    <x-field label="Apply to" name="overrideScope"><select wire:model.live="overrideScope"><option value="employee">One employee</option><option value="employees">Several employees</option><option value="team">A whole team</option></select></x-field>
                    @if($overrideScope === 'team')<x-field label="Team" name="overrideTeamId"><select wire:model="overrideTeamId"><option value="">Select</option>@foreach($teams as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></x-field>
                    @elseif($overrideScope === 'employees')
                        {{-- Tick each person (e.g. the ones who are late every day): the same rule is saved for each of them. --}}
                        <x-field label="Employees ({{ count($overrideEmployeeIds) }} ticked)" name="overrideEmployeeIds" full><div x-data="{ q: '' }"><input type="search" x-model="q" placeholder="Find a name" style="margin-bottom:6px"><div style="max-height:180px;overflow:auto;border:1px solid var(--line);border-radius:8px;padding:6px 10px">@foreach($employees as $e)<label class="check" style="display:flex" x-show="! q || @js(mb_strtolower($e->name)).includes(q.toLowerCase())"><input type="checkbox" value="{{ $e->id }}" wire:model.live="overrideEmployeeIds"> {{ $e->name }}</label>@endforeach</div></div></x-field>
                    @else<x-field label="Employee" name="overrideEmployeeId"><select wire:model="overrideEmployeeId"><option value="">Select</option>@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></x-field>@endif
                    <x-field label="Rule" name="overrideKey"><select wire:model.live="overrideKey">@foreach($overridable as $key => [$label])<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></x-field>
                    </fieldset>
                    @foreach($overridable[$overrideKey][1] as $field => $fieldLabel)
                        @if($field === 'field')
                            <x-field :label="$fieldLabel" :name="'overrideValues.'.$field" hint="Field / mobile: idle time on the computer is shown but not taken off the working time, and the desktop app does not ask 'Are you sitting idle?'"><select wire:model="overrideValues.{{ $field }}"><option value="">Select</option><option value="0">Desk (idle is taken off)</option><option value="1">Field / mobile (idle not taken off)</option></select></x-field>
                        @elseif($field === 'hours_cut')
                            <x-field :label="$fieldLabel" :name="'overrideValues.'.$field" hint="Yes: on top of the late fine, the time short of the working day is cut at the hourly rate"><select wire:model="overrideValues.{{ $field }}"><option value="">Select</option><option value="0">No: only the late fine</option><option value="1">Yes: late fine + pay only the hours worked</option></select></x-field>
                        @elseif($field === 'deduct_now')
                            <x-field :label="$fieldLabel" :name="'overrideValues.'.$field"><select wire:model="overrideValues.{{ $field }}"><option value="">Select</option><option value="0">No: wait for HR approval</option><option value="1">Yes: deduct automatically</option></select></x-field>
                        @else
                            <x-field :label="$fieldLabel" :name="'overrideValues.'.$field"><input type="number" step="0.01" min="0" wire:model="overrideValues.{{ $field }}"></x-field>
                        @endif
                    @endforeach
                    <x-field label="Effective from" name="overrideFrom"><input type="date" wire:model="overrideFrom"></x-field>
                </div><div class="form-actions">@if($editing)<button type="button" class="btn" wire:click="cancelEdit">Cancel</button>@endif<button class="btn btn-primary">{{ $editing ? 'Save changes' : 'Save override' }}</button></div></form>
            </section>
        @endif
        <section class="card">
            <div class="card-head"><h2>Active overrides & rule history</h2></div>
            <div class="list">
                {{-- Each own rule: its dates, Edit (values / start date) and Stop (the global rule again from a date). --}}
                @foreach([...$teamOverrides->map(fn ($t) => ['team:'.$t->id, 'Team '.$t->team?->name, $t, 'team', 'blue']), ...collect($overrides->items())->map(fn ($o) => ['employee:'.$o->id, $o->employee?->name, $o, 'override', 'violet'])] as [$ref, $who, $o, $badge, $tone])
                    @php($ended = $o->effective_until && $o->effective_until->lt(today()))
                    <div class="list-row" wire:key="ov-{{ $ref }}" @if($ended) style="opacity:.6" @endif><div><strong>{{ $who }} · {{ $overridable[$o->rule_key][0] ?? $o->rule_key }}</strong><small>from {{ $o->effective_from?->format('d M Y') ?? 'always' }}@if($o->effective_until) · until {{ $o->effective_until->format('d M Y') }}@endif · {{ collect($o->configuration)->map(fn ($v, $k) => $k === 'field' ? ($v ? 'Field / mobile' : 'Desk') : str($k)->replace('_', ' ').': '.$v)->join(', ') }}</small></div>
                        <div class="row-actions" style="align-items:center">@if($ended)<x-badge tone="gray">stopped</x-badge>@else<x-badge :tone="$tone">{{ $badge }}</x-badge>@endif
                            @if($canConfigure && isset($overridable[$o->rule_key]))<button type="button" class="btn btn-sm" wire:click="editOverride('{{ $ref }}')">Edit</button>@if(! $ended)<button type="button" class="btn btn-sm btn-danger" wire:click="openStop('{{ $ref }}')">Stop</button>@endif @endif</div></div>
                @endforeach
                {{ $overrides->links() }}
                @foreach($history as $r)<div class="list-row"><div><strong>{{ $rules[$r->rule_key][0] ?? $r->rule_key }}</strong><small>from {{ $r->effective_from?->format('d M Y') ?? 'always' }} · {{ collect($r->configuration)->map(fn ($v, $k) => str($k)->replace('_', ' ').': '.$v)->join(', ') }}</small></div><x-badge tone="blue">global</x-badge></div>@endforeach
                @if($overrides->isEmpty() && $teamOverrides->isEmpty() && $history->isEmpty())<x-empty title="No compliance rules configured yet" icon="shield" />@endif
            </div>
        </section>
    </div>
    @if($stopping)
        <x-modal title="Stop this rule" close="closeStop">
            <form wire:submit="saveStop"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Global rule again from" name="stopFrom" full><input type="date" wire:model="stopFrom"></x-field>
            </div><p class="small muted">From this day the person (or team) follows the normal rule for everyone again, including later changes to it. Days before keep this rule and their fines (a fine can still be removed with Give reason or Waive). Choose the rule's own start date to remove it completely.</p></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeStop">Cancel</button><button class="btn btn-danger" wire:loading.attr="disabled">Stop</button></div></form>
        </x-modal>
    @endif
</div>
