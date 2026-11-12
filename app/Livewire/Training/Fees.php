<?php

namespace App\Livewire\Training;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\TrainingEnrollment;
use App\Models\TrainingCourse;
use App\Models\TrainingEnrollmentTopic;
use App\Models\TrainingFeePayment;
use App\Services\EmployeeService;
use App\Services\TrainingFeeService;
use App\Services\TrainingMentorService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Training > Course fees: trainees pay for a course, in one go or in installments. Shows who is enrolled
 * in which course, what they owe, what they paid (when, how, receipt) and what is still due. Kept apart
 * from salary and payroll: trainees are never paid.
 */
class Fees extends Component
{
    use InteractsWithHr, \App\Livewire\Concerns\EditsTrainingCourse, \App\Livewire\Concerns\WithPaginationNoUrl;

    #[Url] public string $tab = 'trainees';
    #[Url] public string $search = '';
    #[Url] public string $courseFilter = '';
    #[Url] public string $statusFilter = 'active';
    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public string $methodFilter = '';
    #[Url] public string $mentorFilter = '';

    // Enrolment form
    public bool $showEnroll = false;
    public ?int $enrollmentId = null;
    public ?int $employeeId = null;
    public ?int $feeCourseId = null;
    public string $courseName = '';
    public string $totalFee = '';
    public string $discount = '0';
    public string $installments = '1';
    public string $startsOn = '';
    public string $endsOn = '';
    public string $enrollStatus = 'active';
    public string $notes = '';
    /** @var list<array{amount: string|float, due_on: string}> Installments: amount and due date each (HR can change them). */
    public array $planRows = [];
    /** True once HR changed an installment by hand; until then the plan follows the fee, discount and count. */
    public bool $planEdited = false;
    /** How the trainee pays: 'once' (the lower fee) or 'installments' (the full fee). */
    public string $payMode = 'installments';
    /** @var list<array{id: int|null, course_topic_id: int|null, title: string, mentor_id: string, status: string}> The trainee's topics and mentors. */
    public array $enrollTopics = [];

    public function updatedPlanRows(): void
    {
        $this->planEdited = true;
    }

    // Payment form
    public ?int $payingId = null;
    public string $amount = '';
    public string $paidOn = '';
    public string $method = 'cash';
    public string $reference = '';
    public string $paymentNote = '';

    public ?int $viewingId = null;

    public function mount(): void
    {
        $this->from = $this->from ?: today()->startOfMonth()->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'courseFilter', 'statusFilter', 'from', 'to', 'methodFilter', 'mentorFilter', 'tab'], true)) $this->resetPage();
    }

    /** Picking a course fills its fee, the usual installments, the end date and its topics with their mentors. */
    public function updatedFeeCourseId($id): void
    {
        if (! $id) { $this->courseName = ''; $this->enrollTopics = []; return; } // "a course not in the list": type its name
        if ($course = TrainingCourse::find($id)) {
            $this->courseName = $course->title;
            $this->totalFee = (string) (float) $course->fee;
            if ($this->payMode === 'installments' && $course->default_installments) $this->installments = (string) max(2, $course->default_installments);
            if ((int) $this->installments === 1) $this->payMode = 'once';
            $this->endsOn = (string) ($course->endDateFrom($this->startsOn ?: today()->toDateString()) ?? $this->endsOn);
            $this->enrollTopics = TrainingMentorService::topicsFromCourse($course);
            $this->applyDiscountRule();
            $this->equalPlan();
        }
    }

    /** At once: one payment of the lower fee; in installments: the full fee in the course's usual number of installments. */
    public function updatedPayMode(): void
    {
        $course = $this->feeCourseId ? TrainingCourse::find($this->feeCourseId) : null;
        if ($this->payMode === 'once') $this->installments = '1';
        elseif ((int) $this->installments <= 1) $this->installments = (string) max(2, (int) ($course?->default_installments ?: 3));
        $this->applyDiscountRule();
        $this->equalPlan();
    }

    /** Paid at once: the course's discount; in installments: no discount. */
    public function updatedInstallments(): void
    {
        $this->payMode = (int) $this->installments === 1 ? 'once' : 'installments';
        $this->applyDiscountRule();
        $this->equalPlan();
    }

    /** A new start date moves the end date (by the course's duration) and the installment dates. */
    public function updatedStartsOn(): void
    {
        $course = $this->feeCourseId ? TrainingCourse::find($this->feeCourseId) : null;
        if ($course && $this->startsOn && ($end = $course->endDateFrom($this->startsOn))) $this->endsOn = $end;
        if (! $this->planEdited) $this->equalPlan();
    }

    /** Fills the installments as equal monthly amounts of the fee less the discount, from the start date. */
    public function equalPlan(): void
    {
        $payable = max(0, (float) $this->totalFee - (float) $this->discount);
        $this->planEdited = false;
        $this->planRows = array_map(fn ($r) => ['amount' => number_format($r['amount'], 2, '.', ''), 'due_on' => $r['due_on']],
            TrainingEnrollment::equalPlan($payable, max(1, min(36, (int) $this->installments)), $this->startsOn ?: today()->toDateString()));
    }

    private function applyDiscountRule(): void
    {
        $course = $this->feeCourseId ? TrainingCourse::find($this->feeCourseId) : null;
        if (! $course) return;
        $this->discount = (int) $this->installments === 1 ? (string) (float) $course->one_time_discount : '0';
    }

    public function enroll(?int $id = null, ?int $traineeId = null): void
    {
        $this->resetValidation();
        $e = $id ? TrainingEnrollment::findOrFail($id) : null;
        $this->enrollmentId = $e?->id;
        $this->employeeId = $e?->employee_id ?? $traineeId;
        $this->feeCourseId = $e?->training_course_id;
        $this->courseName = (string) $e?->course_name;
        $this->totalFee = $e ? (string) (float) $e->total_fee : '';
        $this->discount = $e ? (string) (float) $e->discount : '0';
        $this->installments = (string) ($e?->installments ?? 1);
        $this->startsOn = (string) ($e?->starts_on?->toDateString() ?? today()->toDateString());
        $this->endsOn = (string) $e?->ends_on?->toDateString();
        $this->enrollStatus = $e?->status ?? 'active';
        $this->notes = (string) $e?->notes;
        $this->payMode = (int) $this->installments === 1 ? 'once' : 'installments';
        $this->enrollTopics = $e ? $e->topics()->get()->map(fn ($t) => ['id' => $t->id, 'course_topic_id' => $t->course_topic_id, 'title' => $t->title, 'mentor_id' => (string) $t->mentor_id, 'status' => $t->status])->all() : [];
        if ($e && ! $this->enrollTopics && $e->course) $this->enrollTopics = TrainingMentorService::topicsFromCourse($e->course);
        if ($e) {
            $this->planRows = array_map(fn ($r) => ['amount' => number_format((float) $r['amount'], 2, '.', ''), 'due_on' => (string) $r['due_on']], $e->plan());
            $this->planEdited = ! empty($e->schedule);
        } else {
            $this->equalPlan();
        }
        $this->showEnroll = true;
    }

    public function saveEnrollment(TrainingFeeService $fees): void
    {
        $data = $this->validate([
            'employeeId' => [$this->enrollmentId ? 'nullable' : 'required', 'exists:attendance_employees,id'],
            'feeCourseId' => ['nullable', 'integer', function ($attr, $value, $fail) { if ($value && ! TrainingCourse::whereKey($value)->exists()) $fail('Choose a course from the list.'); }],
            'courseName' => [$this->feeCourseId ? 'nullable' : 'required', 'string', 'max:255'],
            'totalFee' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'installments' => ['required', 'integer', 'min:1', 'max:36'],
            'startsOn' => ['nullable', 'date'],
            'endsOn' => ['nullable', 'date', 'after_or_equal:startsOn'],
            'enrollStatus' => ['required', Rule::in(array_keys(TrainingEnrollment::STATUSES))],
            'notes' => ['nullable', 'string', 'max:2000'],
            'enrollTopics' => ['array'], 'enrollTopics.*.mentor_id' => ['nullable', 'integer', 'exists:attendance_employees,id'],
            'enrollTopics.*.status' => ['required', Rule::in(array_keys(TrainingEnrollmentTopic::STATUSES))],
        ], [], ['employeeId' => 'trainee', 'courseName' => 'course', 'totalFee' => 'fee', 'endsOn' => 'end date']);
        // The installments must be one row each and add up to the fee less the discount. Not changed by hand: they
        // simply follow the fee, discount, count and start date as saved.
        if (! $this->planEdited) { $this->totalFee = (string) $data['totalFee']; $this->discount = (string) ($data['discount'] ?: 0); $this->equalPlan(); }
        $this->validate(['planRows' => ['array'], 'planRows.*.amount' => ['required', 'numeric', 'gt:0'], 'planRows.*.due_on' => ['required', 'date']], [], ['planRows.*.amount' => 'installment amount', 'planRows.*.due_on' => 'due date']);
        $payable = round(max(0, (float) $data['totalFee'] - (float) ($data['discount'] ?: 0)), 2);
        $sum = round(array_sum(array_map(fn ($r) => (float) $r['amount'], $this->planRows)), 2);
        if (count($this->planRows) !== (int) $data['installments'] || abs($sum - $payable) >= 0.01) {
            throw \Illuminate\Validation\ValidationException::withMessages(['planRows' => 'The '.count($this->planRows).' installments add up to '.number_format($sum, 2).', but '.(int) $data['installments'].' installments of '.number_format($payable, 2).' are needed. Press "Equal monthly" or fix the amounts.']);
        }
        $schedule = array_map(fn ($r) => ['amount' => round((float) $r['amount'], 2), 'due_on' => \Illuminate\Support\Carbon::parse($r['due_on'])->toDateString()], array_values($this->planRows));
        $course = $data['feeCourseId'] ? TrainingCourse::find($data['feeCourseId']) : null;
        $values = ['schedule' => $schedule, 'training_course_id' => $course?->id, 'course_name' => $course?->title ?? $data['courseName'], 'total_fee' => $data['totalFee'], 'discount' => $data['discount'] ?: 0,
            'installments' => (int) $data['installments'], 'starts_on' => $data['startsOn'] ?: null, 'ends_on' => $data['endsOn'] ?: null, 'notes' => $data['notes'] ?: null];
        if ($this->enrollmentId) {
            $enrollment = $fees->updateEnrollment(TrainingEnrollment::findOrFail($this->enrollmentId), [...$values, 'status' => $data['enrollStatus']]);
            $this->toast('Enrolment updated.');
        } else {
            $enrollment = $fees->enroll(AttendanceEmployee::findOrFail($data['employeeId']), $values, $this->user());
            $this->toast('Trainee enrolled.');
        }
        // Each topic with the mentor picked for this trainee; the mentor of the topic going on is told.
        app(TrainingMentorService::class)->saveEnrollmentTopics($enrollment, $this->enrollTopics, $this->user());
        $this->showEnroll = false;
    }

    public function pay(int $id): void
    {
        $this->resetValidation();
        $e = TrainingEnrollment::with('payments')->findOrFail($id);
        $this->payingId = $e->id;
        $this->amount = (string) $e->nextInstallment();
        $this->paidOn = today()->toDateString();
        $this->method = 'cash';
        $this->reference = '';
        $this->paymentNote = '';
    }

    public function savePayment(TrainingFeeService $fees): void
    {
        $data = $this->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'paidOn' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::in(array_keys(TrainingFeePayment::METHODS))],
            'reference' => [in_array($this->method, ['upi', 'bank_transfer', 'cheque'], true) ? 'required' : 'nullable', 'string', 'max:120'],
            'paymentNote' => ['nullable', 'string', 'max:1000'],
        ], ['reference.required' => 'Enter the UPI / transaction / cheque number.'], ['paidOn' => 'date']);
        $payment = $fees->recordPayment(TrainingEnrollment::findOrFail($this->payingId), ['amount' => $data['amount'], 'paid_on' => $data['paidOn'], 'method' => $data['method'], 'reference' => $data['reference'] ?: null, 'note' => $data['paymentNote'] ?: null], $this->user());
        $this->payingId = null;
        $this->toast("Payment recorded · receipt {$payment->receipt_no}.");
    }

    public function deletePayment(int $id, TrainingFeeService $fees): void
    {
        $fees->deletePayment(TrainingFeePayment::findOrFail($id), $this->user());
        $this->toast('Payment removed.');
    }

    public function closeModal(): void
    {
        $this->showEnroll = false;
        $this->showCourseForm = false;
        $this->payingId = null;
        $this->viewingId = null;
    }

    private function paymentsQuery()
    {
        return TrainingFeePayment::with(['employee', 'enrollment', 'receiver'])
            ->whereDate('paid_on', '>=', $this->from ?: '1900-01-01')->whereDate('paid_on', '<=', $this->to ?: today()->toDateString())
            ->when($this->methodFilter !== '', fn ($q) => $q->where('method', $this->methodFilter))
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q->where('receipt_no', 'like', '%'.trim($this->search).'%')->orWhereHas('employee', fn ($e) => $e->where('name', 'like', '%'.trim($this->search).'%')->orWhere('employee_code', 'like', '%'.trim($this->search).'%'))));
    }

    /** CSV of the payments shown (Payments tab). */
    public function exportPayments()
    {
        $rows = $this->paymentsQuery()->orderBy('paid_on')->orderBy('id')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Receipt', 'Date', 'Trainee code', 'Trainee', 'Course', 'Installment', 'Amount', 'Method', 'Reference', 'Received by', 'Note']);
            foreach ($rows as $p) fputcsv($out, [$p->receipt_no, $p->paid_on->toDateString(), $p->employee?->employee_code, $p->employee?->name, $p->enrollment?->course_name, $p->installment_no, (float) $p->amount, TrainingFeePayment::METHODS[$p->method] ?? $p->method, $p->reference, $p->receiver?->name, $p->note]);
            fclose($out);
        }, 'course-fee-payments-'.$this->from.'-to-'.$this->to.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render()
    {
        $enrollments = TrainingEnrollment::with(['employee.department', 'payments', 'topics.mentor:id,name'])
            ->when($this->mentorFilter !== '', fn ($q) => $q->whereHas('topics', fn ($t) => $t->where('mentor_id', $this->mentorFilter)->where('status', 'active')))
            ->when($this->courseFilter !== '', fn ($q) => $q->where('course_name', $this->courseFilter))
            ->when(in_array($this->statusFilter, array_keys(TrainingEnrollment::STATUSES), true), fn ($q) => $q->where('status', $this->statusFilter))
            ->when(trim($this->search) !== '', fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('name', 'like', '%'.trim($this->search).'%')->orWhere('employee_code', 'like', '%'.trim($this->search).'%')))
            ->latest('id')->get();
        if ($this->statusFilter === 'due') $enrollments = $enrollments->filter(fn ($e) => $e->balance() > 0)->values();
        $all = TrainingEnrollment::with('payments')->where('status', '!=', 'dropped')->get();

        return $this->page('livewire.training.fees', [
            'enrollments' => $enrollments,
            'summary' => [
                'enrolled' => $all->where('status', 'active')->count(),
                'payable' => $all->sum(fn ($e) => $e->payable()),
                'collected' => $all->sum(fn ($e) => $e->paid()),
                'due' => $all->sum(fn ($e) => $e->balance()),
                'this_month' => (float) TrainingFeePayment::whereDate('paid_on', '>=', today()->startOfMonth()->toDateString())->whereDate('paid_on', '<=', today()->toDateString())->sum('amount'),
            ],
            'payments' => $this->tab === 'payments' ? $this->paymentsQuery()->orderByDesc('paid_on')->orderByDesc('id')->paginate(25) : null,
            'paymentsTotal' => $this->tab === 'payments' ? (float) $this->paymentsQuery()->sum('amount') : 0,
            'courses' => TrainingCourse::with('topics.mentors:id,name')->withCount('enrollments')->orderByDesc('is_active')->orderBy('title')->get(),
            'mentorOptions' => ($this->showCourseForm || $this->showEnroll) ? $this->mentorOptions() : collect(),
            // Mentors teaching a topic now, for the "Mentor" filter.
            'mentors' => AttendanceEmployee::whereIn('id', TrainingEnrollmentTopic::where('status', 'active')->whereNotNull('mentor_id')->distinct()->pluck('mentor_id'))->orderBy('name')->get(['id', 'name']),
            // Trainees not in a course yet: shown with an "Enrol" button.
            'unenrolled' => $this->tab === 'trainees' ? AttendanceEmployee::where('is_active', true)->where('employment_type', EmployeeService::TRAINEE)
                ->whereDoesntHave('trainingEnrollments', fn ($q) => $q->whereIn('status', ['active', 'completed']))->inCodeOrder()->get(['id', 'name', 'employee_code', 'joined_at']) : collect(),
            'courseNames' => TrainingEnrollment::distinct()->orderBy('course_name')->pluck('course_name'),
            'trainees' => $this->showEnroll ? AttendanceEmployee::where('is_active', true)->where('employment_type', EmployeeService::TRAINEE)->inCodeOrder()->get(['id', 'name', 'employee_code']) : collect(),
            'paying' => $this->payingId ? TrainingEnrollment::with(['employee', 'payments'])->find($this->payingId) : null,
            'viewing' => $this->viewingId ? TrainingEnrollment::with(['employee', 'payments.receiver'])->find($this->viewingId) : null,
            'isAdmin' => \App\Support\Access::isAdmin($this->user()),
        ], 'Course fees');
    }
}
