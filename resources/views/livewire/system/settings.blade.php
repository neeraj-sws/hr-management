<div>
    <div class="page-head"><div><h1>Settings</h1><p>Employer policies are saved as effective-dated rules starting today; history is kept and individual employees can have overrides (see Compliance). No statutory or legal rates are built in.</p></div></div>
    <x-errors />
    <div class="tabs">
        @foreach(['leave' => 'Leave types', 'calendar' => 'Working calendar', 'hours' => 'Work hours & attendance', 'locations' => 'Office locations', 'capture' => 'Screen capture', 'payroll' => 'Payroll & overtime', 'statutory' => 'Statutory components'] as $key => $label)
            <button class="tab {{ $tab === $key ? 'active' : '' }}" wire:click="$set('tab','{{ $key }}')">{{ $label }}</button>
        @endforeach
    </div>

    @if($tab === 'leave')
        <section class="card">
            <div class="card-head"><div><h2>Leave types</h2><p>Monthly accrual &gt; 0 means balances build up each month (via the scheduled <code>leave:accrue</code> job); otherwise the annual allowance is granted up-front.</p></div><button class="btn btn-primary btn-sm" wire:click="editType"><x-icon name="plus" class="icon-sm" />Add leave type</button></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Name</th><th class="num">Annual</th><th class="num">Monthly accrual</th><th>Carry forward</th><th>Advance</th><th>Paid</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @foreach($leaveTypes as $t)
                    <tr><td><strong>{{ $t->name }}</strong></td><td class="num">{{ (float) $t->annual_allowance }}</td><td class="num">{{ (float) $t->monthly_accrual }}</td>
                        <td>{{ $t->carry_forward ? 'up to '.(float) $t->carry_forward_limit.' days'.($t->carry_forward_expiry_months ? ', expires after '.$t->carry_forward_expiry_months.' mo' : '') : 'No' }}</td>
                        <td>{{ $t->allow_advance ? 'Allowed' : 'No' }}</td><td>{{ $t->paid ? 'Paid' : 'Unpaid' }}</td><td><x-badge :status="$t->is_active ? 'active' : 'inactive'" /></td>
                        <td><button class="btn btn-sm" wire:click="editType({{ $t->id }})">Edit</button></td></tr>
                @endforeach
                </tbody>
            </table></div>
        </section>
    @elseif($tab === 'calendar')
        <section class="card">
            <form class="card-body stack" wire:submit="saveCalendar">
                <div class="field"><span class="label">Working days</span><div class="pill-row">@foreach([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $num => $day)<label class="check pill"><input type="checkbox" value="{{ $num }}" wire:model="workdays"> {{ $day }}</label>@endforeach</div>@error('workdays')<span class="error">{{ $message }}</span>@enderror</div>
                <div class="field"><span class="label">Holidays</span>
                    <div class="toolbar"><input type="date" class="input" style="width:auto" wire:model="newHoliday" wire:keydown.enter.prevent="addHoliday" aria-label="Holiday date"><input class="input" style="width:200px" wire:model="newHolidayName" wire:keydown.enter.prevent="addHoliday" placeholder="Name, e.g. Diwali" aria-label="Holiday name" maxlength="80"><button type="button" class="btn" wire:click="addHoliday">Add holiday</button></div>
                    @error('newHoliday')<span class="error">{{ $message }}</span>@enderror
                    @php($holidayList = $this->holidayList())
                    @if($holidayList)
                        <div class="pill-row">@foreach($holidayList as $date)<span class="pill">{{ \Illuminate\Support\Carbon::parse($date)->format('D, d M Y') }}@if(! empty($holidayNames[$date])) · <b>{{ $holidayNames[$date] }}</b>@endif <button type="button" class="btn btn-sm" style="padding:0 6px" wire:click="removeHoliday('{{ $date }}')" aria-label="Remove {{ $date }}">&times;</button></span>@endforeach</div>
                    @else
                        <span class="small muted">No holidays added yet.</span>
                    @endif
                    @error('holidays')<span class="error">{{ $message }}</span>@enderror
                    <span class="small muted">Changes are kept after you press "Save calendar".</span>
                </div>
                <p class="small muted">Used for leave day counts, payroll proration, absence checks, overdue-task day counts and final settlement.</p>
                <div><button class="btn btn-primary">Save calendar</button></div>
            </form>
        </section>
    @elseif($tab === 'hours')
        <section class="card">
            <form class="card-body" wire:submit="saveHours"><div class="form-grid">
                <x-field label="Default start time" name="startTime" hint="employee schedule overrides"><input type="time" wire:model="startTime"></x-field>
                <x-field label="Default end time" name="endTime"><input type="time" wire:model="endTime"></x-field>
                <x-field label="Standard hours per day" name="dailyHours"><input type="number" step="0.25" wire:model="dailyHours"></x-field>
                <x-field label="Late grace (minutes)" name="graceMinutes"><input type="number" min="0" wire:model="graceMinutes"></x-field>
                <x-field label="Half-day if worked less than (hours)" name="halfDayHours"><input type="number" step="0.25" wire:model="halfDayHours"></x-field>
                <x-field label="Desktop app: ask about breaks after (minutes away)" name="idleMinutes" hint="no mouse/keyboard use, screen locked or asleep · 0 = never ask"><input type="number" min="0" max="240" wire:model="idleMinutes"></x-field>
            </div><div class="form-actions"><button class="btn btn-primary">Save</button></div></form>
        </section>
    @elseif($tab === 'capture')
        @if(! $canCapture)
            <section class="card"><div class="card-body"><p class="muted">Only an administrator can change screen capture.</p></div></section>
        @else
        <section class="card">
            <div class="card-head"><div><h2>Employees</h2><p>Switch screen capture on only for the people you choose. Nothing is captured for anyone else.</p></div></div>
            <div class="table-wrap"><table class="table"><thead><tr><th>Employee</th><th>Department</th><th>Screen capture</th><th></th></tr></thead><tbody>
                @foreach($captureEmployees as $row)
                    <tr><td><strong>{{ $row['employee']->name }}</strong> <span class="small muted">{{ $row['employee']->employee_code }}</span></td><td>{{ $row['employee']->department?->name ?? '—' }}</td>
                        <td>@if($row['on'])<x-badge tone="green">on</x-badge>@else<x-badge tone="gray">off</x-badge>@endif</td>
                        <td class="num"><button type="button" class="btn btn-sm {{ $row['on'] ? '' : 'btn-primary' }}" wire:click="toggleEmployeeCapture({{ $row['employee']->id }})" wire:loading.attr="disabled">{{ $row['on'] ? 'Turn off' : 'Turn on' }}</button></td></tr>
                @endforeach
            </tbody></table></div>
        </section>
        <section class="card">
            <div class="card-head"><div><h2>Capture rules</h2><p>Apply to everyone who has capture on.</p></div></div>
            <form class="card-body" wire:submit="saveCapturePolicy">
                <label class="check"><input type="checkbox" wire:model="captureEnabled"> Turn on for <strong>all</strong> employees</label>
                <p class="small muted">Off by default — use the list above to choose people. Captures are taken only while the employee is checked in and working — never before check-in, during a break or after check-out. The desktop app shows the employee that capture is on. Only the employee, their manager and people with the "monitoring.view" permission can open the pictures. Keystrokes, passwords, webcam, microphone and clipboard are never recorded.</p>
                <div class="form-grid mt">
                    <x-field label="Every" name="captureInterval"><select wire:model="captureInterval">@foreach(\App\Services\ScreenCaptureService::INTERVALS as $m)<option value="{{ $m }}">{{ $m }} minutes</option>@endforeach</select></x-field>
                    <x-field label="Keep captures for (days)" name="captureRetention"><input type="number" min="1" max="365" wire:model="captureRetention"></x-field>
                    <x-field label="Only between (start)" name="captureWindowStart" hint="optional work window"><input type="time" wire:model="captureWindowStart"></x-field>
                    <x-field label="and (end)" name="captureWindowEnd"><input type="time" wire:model="captureWindowEnd"></x-field>
                    <label class="check full"><input type="checkbox" wire:model="captureActiveOnly"> Only when the computer is in use (skip if idle or screen locked)</label>
                </div>
                <div class="form-actions"><button class="btn btn-primary">Save rules</button></div>
            </form>
        </section>
        @endif
    @elseif($tab === 'locations')
        <section class="card">
            <form class="card-body" wire:submit="saveLocationPolicy">
                <label class="check"><input type="checkbox" wire:model="requireLocation"> Mobile check-in and check-out must be at an office</label>
                @error('requireLocation')<span class="error">{{ $message }}</span>@enderror
                <p class="small muted">The phone shares its position only at the moment of check-in or check-out — there is no tracking in between. Web and desktop check-in are not affected.</p>
                <div class="form-actions"><button class="btn btn-primary">Save</button></div>
            </form>
        </section>
        <section class="card">
            <div class="card-head"><div><h2>Offices</h2><p>Tip: open the office in Google Maps, right-click the building and copy the latitude, longitude.</p></div><button class="btn btn-primary btn-sm" wire:click="editOffice"><x-icon name="plus" class="icon-sm" />Add office</button></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Office</th><th>Latitude, longitude</th><th class="num">Radius</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse($offices as $o)
                    <tr wire:key="office-{{ $o->id }}"><td><strong>{{ $o->name }}</strong></td><td>{{ $o->latitude }}, {{ $o->longitude }}</td><td class="num">{{ $o->radius_meters }} m</td><td><x-badge :status="$o->is_active ? 'active' : 'inactive'" /></td><td><button class="btn btn-sm" wire:click="editOffice({{ $o->id }})">Edit</button></td></tr>
                @empty
                    <tr><td colspan="5"><x-empty title="No offices yet" icon="building" /></td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
        @if($showOfficeForm)
            <x-modal :title="$officeId ? 'Edit office' : 'Add office'">
                <form wire:submit="saveOffice"><div class="modal-body"><div class="form-grid">
                    <x-field label="Name" name="officeName" full><input wire:model="officeName" placeholder="Head office"></x-field>
                    <x-field label="Latitude" name="officeLat"><input wire:model="officeLat" inputmode="decimal" placeholder="28.6139"></x-field>
                    <x-field label="Longitude" name="officeLng"><input wire:model="officeLng" inputmode="decimal" placeholder="77.2090"></x-field>
                    <x-field label="Allowed distance (metres)" name="officeRadius" hint="25–5000"><input type="number" min="25" max="5000" wire:model="officeRadius"></x-field>
                    <label class="check"><input type="checkbox" wire:model="officeActive"> Active</label>
                </div></div>
                <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save</button></div></form>
            </x-modal>
        @endif
    @elseif($tab === 'payroll')
        <section class="card">
            <form class="card-body" wire:submit="savePayroll"><div class="form-grid">
                <x-field label="Overtime multiplier" name="otMultiplier" hint="required before paying approved OT"><input type="number" step="0.05" min="0" max="10" wire:model="otMultiplier" placeholder="employer-defined"></x-field>
                <x-field label="Max overtime minutes / day" name="otMaxPerDay" hint="optional"><input type="number" min="1" max="1440" wire:model="otMaxPerDay"></x-field>
                <x-field label="Weekly-off / holiday overtime multiplier" name="otHolidayMultiplier" hint="optional; replaces the normal rate on those days"><input type="number" step="0.05" min="0" max="10" wire:model="otHolidayMultiplier" placeholder="same as normal"></x-field>
                <x-field label="Max overtime hours / month" name="otMaxPerMonthHours" hint="optional"><input type="number" step="0.5" min="0.25" wire:model="otMaxPerMonthHours"></x-field>
                <x-field label="Working days per month (salary divisor)" name="workingDaysPerMonth"><input type="number" min="1" max="31" wire:model="workingDaysPerMonth"></x-field>
                <x-field label="Advance paid leave limit (days)" name="advanceLeaveMax"><input type="number" step="0.5" min="0" wire:model="advanceLeaveMax"></x-field>
                <label class="check"><input type="checkbox" wire:model="attendanceProration"> Prorate salary by attendance (unpaid absence reduces pay)</label>
                <label class="check"><input type="checkbox" wire:model="includePaidLeave"> Count approved paid leave as paid days</label>
                <label class="check"><input type="checkbox" wire:model="encashmentEnabled"> Encash remaining paid leave in final settlement</label>
                <x-field label="Encashment rate per day" name="encashmentRate"><input type="number" step="0.01" min="0" wire:model="encashmentRate"></x-field>
            </div><div class="form-actions"><button class="btn btn-primary">Save policies</button></div></form>
        </section>
        @include('livewire.system.partials.salary-structure')
    @else
        <section class="card">
            <div class="card-head"><div><h2>Statutory components</h2><p>Enter the deductions/contributions required in your jurisdiction. Each item: <code>key</code>, <code>name</code>, <code>type</code> (earning|deduction), <code>basis</code> (gross|basic_salary), exactly one of <code>fixed_amount</code> or <code>rate_bps</code> (basis points: 100 = 1%), optional <code>cap_amount</code>.</p></div></div>
            <form class="card-body stack" wire:submit="saveStatutory">
                <x-field label="Components (JSON)" name="statutoryJson"><textarea wire:model="statutoryJson" rows="12" style="font-family:ui-monospace,Consolas,monospace" spellcheck="false"></textarea></x-field>
                <p class="small muted">Example shape (values are placeholders, not legal rates): <code>[{"key":"fund","name":"Provident fund","type":"deduction","basis":"basic_salary","rate_bps":0}]</code></p>
                <div><button class="btn btn-primary">Validate & save</button></div>
            </form>
        </section>
    @endif

    @if($showTypeForm)
        <x-modal :title="$editingTypeId ? 'Edit leave type' : 'Add leave type'">
            <form wire:submit="saveType"><div class="modal-body"><x-errors /><div class="form-grid">
                <x-field label="Name" name="type.name" full><input wire:model="type.name"></x-field>
                <x-field label="Annual allowance (days)" name="type.annual_allowance"><input type="number" step="0.5" min="0" wire:model="type.annual_allowance"></x-field>
                <x-field label="Monthly accrual (days)" name="type.monthly_accrual"><input type="number" step="0.001" min="0" wire:model="type.monthly_accrual"></x-field>
                <label class="check"><input type="checkbox" wire:model="type.carry_forward"> Allow carry forward</label>
                <x-field label="Carry-forward limit (days)" name="type.carry_forward_limit"><input type="number" step="0.5" min="0" wire:model="type.carry_forward_limit"></x-field>
                <x-field label="Carry-forward expires after (months)" name="type.carry_forward_expiry_months" hint="blank = never"><input type="number" min="1" max="24" wire:model="type.carry_forward_expiry_months"></x-field>
                <label class="check"><input type="checkbox" wire:model="type.allow_advance"> Allow advance leave (negative balance within limit)</label>
                <label class="check"><input type="checkbox" wire:model="type.paid"> Paid leave</label>
                <label class="check"><input type="checkbox" wire:model="type.is_active"> Active</label>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary">Save</button></div></form>
        </x-modal>
    @endif
</div>
