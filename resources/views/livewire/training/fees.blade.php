<div>
    @php($money = fn ($v) => number_format((float) $v, 2))
    <div class="page-head">
        <div><h1>Course fees</h1><p>Trainees pay for their course here, at once or in installments. Kept apart from salary and payroll: trainees are not paid.</p></div>
        <div class="actions">
            <button class="btn" wire:click="editCourse"><x-icon name="plus" class="icon-sm" />New course</button>
            <button class="btn btn-primary" wire:click="enroll"><x-icon name="user-plus" class="icon-sm" />Enrol trainee</button>
        </div>
    </div>

    <div class="stats">
        <div class="stat"><span class="stat-icon blue"><x-icon name="users" /></span><div><span class="stat-label">Studying now</span><span class="stat-value">{{ $summary['enrolled'] }}</span><small>trainees in a course</small></div></div>
        <div class="stat"><span class="stat-icon violet"><x-icon name="file" /></span><div><span class="stat-label">Total fees</span><span class="stat-value">{{ $money($summary['payable']) }}</span><small>after discounts</small></div></div>
        <div class="stat"><span class="stat-icon green"><x-icon name="check-circle" /></span><div><span class="stat-label">Collected</span><span class="stat-value">{{ $money($summary['collected']) }}</span><small>{{ $money($summary['this_month']) }} this month</small></div></div>
        <div class="stat"><span class="stat-icon red"><x-icon name="clock" /></span><div><span class="stat-label">Still due</span><span class="stat-value">{{ $money($summary['due']) }}</span><small>from all trainees</small></div></div>
    </div>

    <div class="tabs">
        @foreach(['trainees' => 'Trainees & balance', 'payments' => 'Payments received', 'courses' => 'Courses & fees'] as $key => $label)
            <button class="tab {{ $tab === $key ? 'active' : '' }}" wire:click="$set('tab','{{ $key }}')">{{ $label }}</button>
        @endforeach
    </div>

    @if($tab === 'trainees')
        @if($unenrolled->isNotEmpty())
            <section class="card mb tr-unenrolled">
                <div class="card-head"><div><h2>Not in a course yet ({{ $unenrolled->count() }})</h2><p>Trainees (People > Trainees) who have no course here. Enrol them to record their fee.</p></div></div>
                <div class="list">
                    @foreach($unenrolled as $t)
                        <div class="list-row" wire:key="ue-{{ $t->id }}"><div class="emp-person"><x-avatar :employee="$t" /><div><strong>{{ $t->name }}</strong><small>{{ $t->employee_code }}@if($t->joined_at) · joined {{ $t->joined_at->format('d M Y') }}@endif</small></div></div>
                            <button type="button" class="btn btn-sm btn-primary" wire:click="enroll(null, {{ $t->id }})"><x-icon name="user-plus" class="icon-sm" />Enrol</button></div>
                    @endforeach
                </div>
            </section>
        @endif
        <section class="card">
            <div class="toolbar">
                <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Trainee name or code"></label>
                <label class="field"><span>Course</span><select wire:model.live="courseFilter"><option value="">All courses</option>@foreach($courseNames as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach</select></label>
                <label class="field"><span>Show</span><select wire:model.live="statusFilter"><option value="active">Studying</option><option value="due">With fees due</option><option value="completed">Completed</option><option value="dropped">Left the course</option><option value="all">Everyone</option></select></label>
            </div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Trainee</th><th>Course</th><th class="num">Fee</th><th class="num">Paid</th><th class="num">Due</th><th>Installments</th><th>Last payment</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse($enrollments as $e)
                    @php($last = $e->payments->last())
                    <tr wire:key="enr-{{ $e->id }}">
                        <td><strong>{{ $e->employee?->name }}</strong><small>{{ $e->employee?->employee_code }}@if($e->employee?->department) · {{ $e->employee->department->name }}@endif</small></td>
                        <td>{{ $e->course_name }}<small>@if($e->starts_on){{ $e->starts_on->format('d M Y') }}@endif @if($e->ends_on)– {{ $e->ends_on->format('d M Y') }}@endif</small></td>
                        <td class="num">{{ $money($e->payable()) }}@if((float) $e->discount)<small>{{ $money($e->total_fee) }} − {{ $money($e->discount) }} discount</small>@endif</td>
                        <td class="num">{{ $money($e->paid()) }}</td>
                        <td class="num"><strong style="color:{{ $e->balance() > 0 ? 'var(--amber)' : 'var(--green)' }}">{{ $money($e->balance()) }}</strong></td>
                        <td>{{ $e->payments->count() }} of {{ $e->installments }} paid @if($e->balance() > 0)<small>next {{ $money($e->nextInstallment()) }}</small>@endif</td>
                        <td>@if($last){{ $last->paid_on->format('d M Y') }}<small>{{ \App\Models\TrainingFeePayment::METHODS[$last->method] ?? $last->method }} · {{ $money($last->amount) }}</small>@else<span class="muted small">nothing yet</span>@endif</td>
                        <td>@if($e->balance() <= 0 && $e->payable() > 0)<x-badge tone="green">Fully paid</x-badge>@endif <x-badge :tone="['active' => 'blue', 'completed' => 'green', 'dropped' => 'gray'][$e->status] ?? 'gray'">{{ \App\Models\TrainingEnrollment::STATUSES[$e->status] ?? $e->status }}</x-badge></td>
                        <td><div class="row-actions">
                            @if($e->balance() > 0)<button class="btn btn-sm btn-primary" wire:click="pay({{ $e->id }})">Record payment</button>@endif
                            <button class="btn btn-sm" wire:click="$set('viewingId', {{ $e->id }})">Payments</button>
                            <button class="btn btn-sm" wire:click="enroll({{ $e->id }})">Edit</button>
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="9"><x-empty title="No trainees here yet" icon="users">Enrol a trainee in a course with "Enrol trainee". Trainees are people whose employment type is "Trainee" (People > Employees).</x-empty></td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
    @elseif($tab === 'payments')
        <section class="card">
            <div class="toolbar">
                <label class="field"><span>From</span><input type="date" wire:model.live="from"></label>
                <label class="field"><span>To</span><input type="date" wire:model.live="to"></label>
                <label class="field"><span>Paid by</span><select wire:model.live="methodFilter"><option value="">Any method</option>@foreach(\App\Models\TrainingFeePayment::METHODS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
                <label class="field grow"><span>Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Trainee or receipt number"></label>
                <button class="btn" wire:click="exportPayments" style="align-self:flex-end"><x-icon name="download" class="icon-sm" />Export CSV</button>
            </div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Receipt</th><th>Date</th><th>Trainee</th><th>Course</th><th>Installment</th><th class="num">Amount</th><th>Paid by</th><th>Reference</th><th>Received by</th>@if($isAdmin)<th></th>@endif</tr></thead>
                <tbody>
                @forelse($payments as $p)
                    <tr wire:key="pay-{{ $p->id }}">
                        <td><strong>{{ $p->receipt_no }}</strong></td><td class="nowrap">{{ $p->paid_on->format('d M Y') }}</td>
                        <td>{{ $p->employee?->name }}<small>{{ $p->employee?->employee_code }}</small></td><td>{{ $p->enrollment?->course_name }}</td>
                        <td>{{ $p->installment_no ? '#'.$p->installment_no.' of '.$p->enrollment?->installments : '—' }}</td>
                        <td class="num">{{ $money($p->amount) }}</td><td>{{ \App\Models\TrainingFeePayment::METHODS[$p->method] ?? $p->method }}</td>
                        <td class="small">{{ $p->reference ?? '—' }}@if($p->note)<small>{{ $p->note }}</small>@endif</td><td class="small">{{ $p->receiver?->name ?? '—' }}</td>
                        @if($isAdmin)<td><button class="btn btn-sm btn-danger" wire:click="deletePayment({{ $p->id }})" wire:confirm="Remove receipt {{ $p->receipt_no }} ({{ $money($p->amount) }})? Use this only for a payment entered by mistake.">Remove</button></td>@endif
                    </tr>
                @empty
                    <tr><td colspan="10"><x-empty title="No payments in these dates" icon="file" /></td></tr>
                @endforelse
                </tbody>
                @if($payments->count())<tfoot><tr><td colspan="5"><strong>Total</strong></td><td class="num"><strong>{{ $money($paymentsTotal) }}</strong></td><td colspan="4"></td></tr></tfoot>@endif
            </table></div>
            {{ $payments->links() }}
        </section>
    @else
        <section class="card">
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Course</th><th>Duration</th><th class="num">Fee</th><th class="num">Discount if paid at once</th><th class="num">Trainees</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse($courses as $c)
                    <tr wire:key="fc-{{ $c->id }}"><td><strong>{{ $c->title }}</strong>@if($c->description)<small>{{ \Illuminate\Support\Str::limit($c->description, 80) }}</small>@endif</td><td>{{ $c->duration_label ?? ($c->duration_hours !== null ? (float) $c->duration_hours.' h' : '—') }}</td><td class="num">{{ $c->fee !== null ? $money($c->fee) : '—' }}</td><td class="num">{{ (float) $c->one_time_discount > 0 ? $money($c->one_time_discount) : '—' }}</td><td class="num">{{ $c->enrollments_count }}</td>
                        <td><x-badge :status="$c->is_active ? 'active' : 'inactive'" /></td><td><button class="btn btn-sm" wire:click="editCourse({{ $c->id }})">Edit</button></td></tr>
                @empty
                    <tr><td colspan="7"><x-empty title="No courses yet" icon="list">Add the courses you teach, e.g. "Web Development - 6 months" with its fee.</x-empty></td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
    @endif

    @if($showEnroll)
        <x-modal :title="$enrollmentId ? 'Edit enrolment' : 'Enrol trainee'" size="lg">
            <form wire:submit="saveEnrollment"><div class="modal-body"><div class="form-grid">
                @if(! $enrollmentId)
                    <x-field label="Trainee" name="employeeId" full hint="people whose employment type is Trainee"><select wire:model="employeeId"><option value="">Select</option>@foreach($trainees as $t)<option value="{{ $t->id }}">{{ $t->name }} ({{ $t->employee_code }})</option>@endforeach</select></x-field>
                @endif
                <x-field label="Course" name="feeCourseId" hint="from Training > Courses"><select wire:model.live="feeCourseId"><option value="">Select a course</option>@foreach($courses->where('is_active', true) as $c)<option value="{{ $c->id }}">{{ $c->title }}{{ $c->fee !== null ? ' · '.$money($c->fee) : '' }}</option>@endforeach<option value="0">A course not in the list…</option></select></x-field>
                @if($feeCourseId === 0 || ($feeCourseId === null && $courseName !== ''))<x-field label="Course name" name="courseName" hint="only for a course not in the list"><input wire:model="courseName"></x-field>@endif
                <x-field label="Fee" name="totalFee"><input type="number" step="0.01" min="0" wire:model="totalFee"></x-field>
                <x-field label="Discount" name="discount" hint="0 if none"><input type="number" step="0.01" min="0" wire:model="discount"></x-field>
                <x-field label="Installments" name="installments" hint="1 = pays at once (gets the course's one-time discount)"><input type="number" min="1" max="36" wire:model.live.debounce.300ms="installments"></x-field>
                <x-field label="Starts" name="startsOn"><input type="date" wire:model="startsOn"></x-field>
                <x-field label="Ends" name="endsOn"><input type="date" wire:model="endsOn"></x-field>
                @if($enrollmentId)<x-field label="Status" name="enrollStatus"><select wire:model="enrollStatus">@foreach(\App\Models\TrainingEnrollment::STATUSES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></x-field>@endif
                <x-field label="Notes" name="notes" full><textarea wire:model="notes" rows="2"></textarea></x-field>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save</button></div></form>
        </x-modal>
    @endif

    @if($paying)
        <x-modal title="Record payment · {{ $paying->employee?->name }}">
            <form wire:submit="savePayment"><div class="modal-body">
                <p class="small" style="margin-top:0">{{ $paying->course_name }} · fee {{ $money($paying->payable()) }} · paid {{ $money($paying->paid()) }} · <strong>due {{ $money($paying->balance()) }}</strong> · installment {{ $paying->payments->count() + 1 }} of {{ $paying->installments }}</p>
                <div class="form-grid">
                    <x-field label="Amount" name="amount"><input type="number" step="0.01" min="0.01" wire:model="amount"></x-field>
                    <x-field label="Date" name="paidOn"><input type="date" wire:model="paidOn" max="{{ today()->toDateString() }}"></x-field>
                    <x-field label="Paid by" name="method"><select wire:model.live="method">@foreach(\App\Models\TrainingFeePayment::METHODS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></x-field>
                    <x-field label="Reference" name="reference" hint="UPI / transaction / cheque number"><input wire:model="reference"></x-field>
                    <x-field label="Note" name="paymentNote" full><input wire:model="paymentNote" placeholder="optional"></x-field>
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save payment</button></div></form>
        </x-modal>
    @endif

    @if($viewing)
        <x-modal title="Payments · {{ $viewing->employee?->name }} · {{ $viewing->course_name }}" size="lg">
            <div class="modal-body">
                <p class="small" style="margin-top:0">Fee {{ $money($viewing->payable()) }} · paid {{ $money($viewing->paid()) }} · <strong>due {{ $money($viewing->balance()) }}</strong> · {{ $viewing->payments->count() }} of {{ $viewing->installments }} installments</p>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Receipt</th><th>Date</th><th>Installment</th><th class="num">Amount</th><th>Paid by</th><th>Reference</th><th>Received by</th></tr></thead>
                    <tbody>
                    @forelse($viewing->payments as $p)
                        <tr><td>{{ $p->receipt_no }}</td><td>{{ $p->paid_on->format('d M Y') }}</td><td>#{{ $p->installment_no }}</td><td class="num">{{ $money($p->amount) }}</td><td>{{ \App\Models\TrainingFeePayment::METHODS[$p->method] ?? $p->method }}</td><td class="small">{{ $p->reference ?? '—' }}@if($p->note)<small>{{ $p->note }}</small>@endif</td><td class="small">{{ $p->receiver?->name ?? '—' }}</td></tr>
                    @empty
                        <tr><td colspan="7"><x-empty title="No payments yet" icon="file" /></td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Close</button>@if($viewing->balance() > 0)<button class="btn btn-primary" wire:click="pay({{ $viewing->id }})">Record payment</button>@endif</div>
        </x-modal>
    @endif

    @if($showCourse)
        <x-modal :title="$courseId ? 'Edit course' : 'New course'">
            <form wire:submit="saveCourse"><div class="modal-body"><div class="form-grid">
                <x-field label="Course name" name="cName" full><input wire:model="cName" placeholder="e.g. Web Development"></x-field>
                <x-field label="Duration" name="cDuration"><input wire:model="cDuration" placeholder="e.g. 6 months"></x-field>
                <x-field label="Fee" name="cFee"><input type="number" step="0.01" min="0" wire:model="cFee"></x-field>
                <x-field label="Discount if paid at once" name="cDiscount" hint="e.g. 3000 off when the whole fee is paid in one go; none for installments"><input type="number" step="0.01" min="0" wire:model="cDiscount"></x-field>
                <x-field label="Description" name="cDescription" full><textarea wire:model="cDescription" rows="2"></textarea></x-field>
                <label class="check"><input type="checkbox" wire:model="cActive"> Offered now</label>
            </div></div>
            <div class="modal-foot"><button type="button" class="btn" wire:click="closeModal">Cancel</button><button class="btn btn-primary" wire:loading.attr="disabled">Save</button></div></form>
        </x-modal>
    @endif
</div>
