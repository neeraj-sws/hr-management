<div>
    <div class="page-head"><div><h1>Settings</h1><p>Employer policies are saved as effective-dated rules starting today; history is kept and individual employees can have overrides (see Compliance). No statutory or legal rates are built in.</p></div></div>
    <x-errors />
    <div class="tabs">
        @foreach(['leave' => 'Leave types', 'calendar' => 'Working calendar & weekly offs', 'hours' => 'Work hours & attendance', 'locations' => 'Office locations', 'capture' => 'Screen capture', 'payroll' => 'Payroll & overtime', 'statutory' => 'Statutory components', 'email' => 'E-mail', 'offboarding' => 'Offboarding'] as $key => $label)
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
                    <tr><td><strong>{{ $t->name }}</strong><small>{{ $t->describe() }}</small></td><td class="num">{{ (float) $t->annual_allowance }}</td><td class="num">{{ (float) $t->monthly_accrual }}</td>
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
                <div class="field"><span class="label">Working days</span><div class="pill-row">@foreach([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $num => $day)<label class="check pill"><input type="checkbox" value="{{ $num }}" wire:model.live="workdays"> {{ $day }}</label>@endforeach</div>@error('workdays')<span class="error">{{ $message }}</span>@enderror</div>
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
                <div class="field"><span class="label">Weekly offs on some weeks only</span>
                    <span class="small muted">Tick the weeks of the month a working day is off — e.g. Saturday is a working day but the <b>3rd Saturday</b> the office is closed.</span>
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>Day</th>@foreach(\App\Support\WorkCalendar::OCCURRENCES as $occurrence => $label)<th>{{ $label }}</th>@endforeach</tr></thead>
                        <tbody>
                        @foreach(\App\Support\WorkCalendar::WEEKDAYS as $num => $dayName)
                            @if(in_array($num, array_map('intval', $workdays), true))
                                <tr><td><strong>{{ $dayName }}</strong></td>@foreach(\App\Support\WorkCalendar::OCCURRENCES as $occurrence => $label)<td><input type="checkbox" value="{{ $occurrence }}" wire:model="offWeeks.{{ $num }}" aria-label="{{ $label }} {{ $dayName }} off"></td>@endforeach</tr>
                            @endif
                        @endforeach
                        </tbody>
                    </table></div>
                </div>
                <div class="field"><span class="label">Company-wide weekly off adjustments</span>
                    @forelse($shifts as $i => $shift)
                        <div class="list-row"><div><strong>{{ \Illuminate\Support\Carbon::parse($shift['from'])->format('D d M Y') }} → working day · {{ \Illuminate\Support\Carbon::parse($shift['to'])->format('D d M Y') }} → off</strong>@if($shift['note'])<small>{{ $shift['note'] }}</small>@endif</div><button type="button" class="btn btn-sm" wire:click="removeShift({{ $i }})">Remove</button></div>
                    @empty
                        <span class="small muted">No adjustments. Add one below when a weekly off moves to another day.</span>
                    @endforelse
                </div>
                <p class="small muted">Used for leave day counts, payroll proration, absence checks, overdue-task day counts and final settlement.</p>
                <div><button class="btn btn-primary">Save calendar</button></div>
            </form>
        </section>

        <section class="card">
            <div class="card-head"><div><h2>Move a weekly off (temporary adjustment)</h2><p>The usual off day becomes a working day and the new day is off — for the whole company or one employee.</p></div></div>
            <form class="card-body" wire:submit="addShift"><div class="form-grid">
                <x-field label="Applies to" name="shiftScope"><select wire:model.live="shiftScope"><option value="company">Whole company</option><option value="employee">One employee</option></select></x-field>
                @if($shiftScope === 'employee')<x-field label="Employee" name="shiftEmployeeId"><select wire:model="shiftEmployeeId"><option value="">Select</option>@foreach($allEmployees as $e)<option value="{{ $e->id }}">{{ $e->name }} ({{ $e->employee_code }})</option>@endforeach</select></x-field>@endif
                <x-field label="Usual off day (becomes working)" name="shiftFrom"><input type="date" wire:model="shiftFrom"></x-field>
                <x-field label="New off day" name="shiftTo"><input type="date" wire:model="shiftTo"></x-field>
                <x-field label="Note" name="shiftNote" hint="optional" full><input wire:model="shiftNote" maxlength="120" placeholder="e.g. Office open on Saturday for the release; Monday off instead"></x-field>
            </div><div class="form-actions"><button class="btn btn-primary" wire:loading.attr="disabled">Add adjustment</button></div></form>
        </section>

        <section class="card">
            <div class="card-head"><div><h2>Employee-specific weekly offs</h2><p>For example, one employee is also off on the 2nd Saturday. Their own pattern replaces the company's for the days you set here.</p></div></div>
            <form class="card-body stack" wire:submit="saveEmployeePattern">
                <x-field label="Employee" name="patternEmployeeId"><select wire:model.live="patternEmployeeId"><option value="">Select an employee</option>@foreach($allEmployees as $e)<option value="{{ $e->id }}">{{ $e->name }} ({{ $e->employee_code }})</option>@endforeach</select></x-field>
                @if($patternEmployeeId)
                    <div class="field"><span class="label">Working days</span><div class="pill-row">@foreach(\App\Support\WorkCalendar::WEEKDAYS as $num => $dayName)<label class="check pill"><input type="checkbox" value="{{ $num }}" wire:model.live="employeeDays"> {{ substr($dayName, 0, 3) }}</label>@endforeach</div>@error('employeeDays')<span class="error">{{ $message }}</span>@enderror</div>
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>Day</th>@foreach(\App\Support\WorkCalendar::OCCURRENCES as $occurrence => $label)<th>{{ $label }} off</th>@endforeach</tr></thead>
                        <tbody>
                        @foreach(\App\Support\WorkCalendar::WEEKDAYS as $num => $dayName)
                            @if(in_array($num, array_map('intval', $employeeDays), true))
                                <tr><td><strong>{{ $dayName }}</strong></td>@foreach(\App\Support\WorkCalendar::OCCURRENCES as $occurrence => $label)<td><input type="checkbox" value="{{ $occurrence }}" wire:model="employeeOffWeeks.{{ $num }}"></td>@endforeach</tr>
                            @endif
                        @endforeach
                        </tbody>
                    </table></div>
                    <div><button class="btn btn-primary" wire:loading.attr="disabled">Save for this employee</button></div>
                @endif
            </form>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Employee</th><th>Own pattern</th><th>Adjustments</th><th></th></tr></thead>
                <tbody>
                @forelse($calendarOverrides as $override)
                    @php($config = (array) $override->configuration)
                    <tr><td><strong>{{ $override->employee->name }}</strong><small>{{ $override->employee->employee_code }}</small></td>
                        <td class="small">{{ isset($config['days']) || isset($config['off_weeks']) ? \App\Support\WorkCalendar::describe([...['days' => [1, 2, 3, 4, 5]], ...$config]) : 'Company pattern' }}</td>
                        <td class="small">@forelse((array) ($config['shifts'] ?? []) as $shift)<div>{{ \Illuminate\Support\Carbon::parse($shift['from'])->format('d M') }} → {{ \Illuminate\Support\Carbon::parse($shift['to'])->format('d M Y') }}@if(! empty($shift['note'])) · {{ $shift['note'] }}@endif</div>@empty — @endforelse</td>
                        <td><div class="row-actions"><button type="button" class="btn btn-sm btn-danger" wire:click="clearEmployeeCalendar({{ $override->employee_id }})" wire:confirm="Remove {{ $override->employee->name }}'s own weekly offs and adjustments?">Use company calendar</button></div></td></tr>
                @empty
                    <tr><td colspan="4"><x-empty title="Everyone follows the company calendar" icon="calendar" /></td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
    @elseif($tab === 'hours')
        <section class="card">
            <form class="card-body" wire:submit="saveHours"><div class="form-grid">
                <x-field label="Default start time" name="startTime" hint="employee schedule overrides"><input type="time" wire:model="startTime"></x-field>
                <x-field label="Default end time" name="endTime"><input type="time" wire:model="endTime"></x-field>
                <x-field label="Default hours per day" name="dailyHours" hint="pre-filled for new employees"><input type="number" step="0.25" wire:model="dailyHours"></x-field>
                <x-field label="Late grace (minutes)" name="graceMinutes"><input type="number" min="0" wire:model="graceMinutes"></x-field>
                <x-field label="Half-day if worked less than (hours)" name="halfDayHours"><input type="number" step="0.25" wire:model="halfDayHours"></x-field>
                <x-field label="Desktop app: ask about breaks after (minutes away)" name="idleMinutes" hint="no mouse/keyboard use, screen locked or asleep · 0 = never ask"><input type="number" min="0" max="240" wire:model="idleMinutes"></x-field>
                <div class="full"><span class="label">Selfie at check-in / check-out</span>
                    <label class="check"><input type="checkbox" wire:model="selfieWeb"> Required on the website</label>
                    <label class="check"><input type="checkbox" wire:model="selfieMobile"> Required in the mobile app</label>
                    <span class="small muted">Photos are stored privately; managers and HR see them in Team Attendance and Attendance records.</span></div>
                <label class="check"><input type="checkbox" wire:model="alertsEnabled"> Late and absence alerts (employee, manager and HR)</label>
                <x-field label="Absent alert after" name="absentAfter" hint="if not checked in by this time"><input type="time" wire:model="absentAfter"></x-field>
                <label class="check full"><input type="checkbox" wire:model="applyHoursToAll"> Also set every active employee's daily hours (and their current salary record) to this value</label>
            </div>
            <p class="small muted">Each employee's own daily hours (Employees → Edit, or Payroll → Salary) are used for attendance, overtime and payroll. The value here is the default for new employees.</p>
            <div class="form-actions"><button class="btn btn-primary">Save</button></div></form>
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
                <label class="check"><input type="checkbox" wire:model="requireLocationWeb"> Website check-in and check-out must be at an office (the browser asks for location)</label>
                @error('requireLocation')<span class="error">{{ $message }}</span>@enderror
                <p class="small muted">The phone or browser shares its position only at the moment of check-in or check-out — there is no tracking in between. The website always records the position when the employee allows it. Desktop check-in is not affected.</p>
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
                <label class="check"><input type="checkbox" wire:model="shortHoursDeduction"> Deduct short hours: on days present, time worked below the employee's working day is deducted at the hourly rate</label>
                <x-field label="Short-hours grace per month (hours)" name="shortHoursGrace" hint="not deducted"><input type="number" step="0.25" min="0" max="200" wire:model="shortHoursGrace"></x-field>
                <label class="check"><input type="checkbox" wire:model="attendanceProration"> Prorate salary by attendance (unpaid absence reduces pay)</label>
                <label class="check"><input type="checkbox" wire:model="includePaidLeave"> Count approved paid leave as paid days</label>
                <label class="check"><input type="checkbox" wire:model="encashmentEnabled"> Encash remaining paid leave in final settlement</label>
                <x-field label="Encashment rate per day" name="encashmentRate"><input type="number" step="0.01" min="0" wire:model="encashmentRate"></x-field>
            </div><div class="form-actions"><button class="btn btn-primary">Save policies</button></div></form>
        </section>
        @include('livewire.system.partials.salary-structure')
    @elseif($tab === 'email')
        <section class="card">
            <div class="card-head"><div><h2>E-mail notifications</h2><p>Approvals, leave, expenses, attendance, tasks and projects are e-mailed to the people concerned (each person can change this in their profile). A delivery problem never blocks the action itself; it is logged here and in the audit log.</p></div></div>
            <div class="card-body stack">
                <dl class="kv">
                    <dt>Mailer</dt><dd><code>{{ $mail['mailer'] }}</code>@if(in_array($mail['mailer'], ['log', 'array'], true)) <x-badge tone="amber">e-mails are not delivered</x-badge> <span class="small muted">Set MAIL_MAILER=smtp and the MAIL_* values in .env.</span>@endif</dd>
                    <dt>Server</dt><dd>{{ $mail['host'] ? $mail['host'].':'.$mail['port'] : '—' }}</dd>
                    <dt>From address</dt><dd>{{ $mail['from'] ?: '—' }}@if(! $mail['from'] || str_ends_with((string) $mail['from'], '@example.com')) <x-badge tone="amber">set MAIL_FROM_ADDRESS</x-badge>@endif</dd>
                    <dt>Queue</dt><dd><code>{{ $mail['queue'] }}</code>@if($mail['queue'] !== 'sync') <span class="small muted">a queue worker (php artisan queue:work) must be running for notifications to be sent</span>@endif</dd>
                    <dt>Last failure</dt><dd>@if($mail['last_error'])<span style="color:var(--red)">{{ $mail['last_error']['at'] }} · to {{ $mail['last_error']['to'] }} · {{ $mail['last_error']['error'] }}</span>@else <span class="muted">none recorded</span>@endif</dd>
                </dl>
                <form class="stack" wire:submit="saveEmail">
                    <label class="check"><input type="checkbox" wire:model="emailEnabled"> Send e-mail notifications (in-app notifications are always on)</label>
                    <div><button class="btn btn-primary">Save</button></div>
                </form>
                <form class="toolbar" wire:submit="sendTestEmail">
                    <x-field label="Send a test e-mail to" name="testEmail"><input type="email" wire:model="testEmail"></x-field>
                    <button class="btn" wire:loading.attr="disabled"><span wire:loading wire:target="sendTestEmail" class="spinner"></span>Send test e-mail</button>
                </form>
            </div>
        </section>
    @elseif($tab === 'offboarding')
        <section class="card">
            <div class="card-head"><div><h2>Offboarding correction window</h2><p>How long after an offboarding case is opened (or closed) it can still be corrected or cancelled by people who manage offboarding. After the window only an administrator can cancel it. Every correction and cancellation needs a reason and is written to the audit log.</p></div></div>
            <form class="card-body" wire:submit="saveOffboarding"><div class="form-grid">
                <x-field label="Correction window (hours)" name="offboardingWindowHours" hint="0 = administrators only"><input type="number" min="0" max="720" wire:model="offboardingWindowHours"></x-field>
            </div><div class="form-actions"><button class="btn btn-primary">Save</button></div></form>
        </section>
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
