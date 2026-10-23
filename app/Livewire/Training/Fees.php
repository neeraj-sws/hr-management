<?php

namespace App\Livewire\Training;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\TrainingEnrollment;
use App\Models\TrainingFeeCourse;
use App\Models\TrainingFeePayment;
use App\Services\EmployeeService;
use App\Services\TrainingFeeService;
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
    use InteractsWithHr, \Livewire\WithPagination;

    #[Url] public string $tab = 'trainees';
    #[Url] public string $search = '';
    #[Url] public string $courseFilter = '';
    #[Url] public string $statusFilter = 'active';
    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public string $methodFilter = '';

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

    // Payment form
    public ?int $payingId = null;
    public string $amount = '';
    public string $paidOn = '';
    public string $method = 'cash';
    public string $reference = '';
    public string $paymentNote = '';

    public ?int $viewingId = null;

    // Course form
    public bool $showCourse = false;
    public ?int $courseId = null;
    public string $cName = '';
    public string $cDuration = '';
    public string $cFee = '';
    public string $cDescription = '';
    public bool $cActive = true;

    public function mount(): void
    {
        $this->from = $this->from ?: today()->startOfMonth()->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'courseFilter', 'statusFilter', 'from', 'to', 'methodFilter', 'tab'], true)) $this->resetPage();
    }

    public function updatedFeeCourseId($id): void
    {
        if ($course = TrainingFeeCourse::find($id)) {
            $this->courseName = $course->name;
            $this->totalFee = (string) (float) $course->fee;
        }
    }

    public function enroll(?int $id = null): void
    {
        $this->resetValidation();
        $e = $id ? TrainingEnrollment::findOrFail($id) : null;
        $this->enrollmentId = $e?->id;
        $this->employeeId = $e?->employee_id;
        $this->feeCourseId = $e?->fee_course_id;
        $this->courseName = (string) $e?->course_name;
        $this->totalFee = $e ? (string) (float) $e->total_fee : '';
        $this->discount = $e ? (string) (float) $e->discount : '0';
        $this->installments = (string) ($e?->installments ?? 1);
        $this->startsOn = (string) ($e?->starts_on?->toDateString() ?? today()->toDateString());
        $this->endsOn = (string) $e?->ends_on?->toDateString();
        $this->enrollStatus = $e?->status ?? 'active';
        $this->notes = (string) $e?->notes;
        $this->showEnroll = true;
    }

    public function saveEnrollment(TrainingFeeService $fees): void
    {
        $data = $this->validate([
            'employeeId' => [$this->enrollmentId ? 'nullable' : 'required', 'exists:attendance_employees,id'],
            'feeCourseId' => ['nullable', 'exists:training_fee_courses,id'],
            'courseName' => ['required', 'string', 'max:255'],
            'totalFee' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'installments' => ['required', 'integer', 'min:1', 'max:36'],
            'startsOn' => ['nullable', 'date'],
            'endsOn' => ['nullable', 'date', 'after_or_equal:startsOn'],
            'enrollStatus' => ['required', Rule::in(array_keys(TrainingEnrollment::STATUSES))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['employeeId' => 'trainee', 'courseName' => 'course', 'totalFee' => 'fee', 'endsOn' => 'end date']);
        $values = ['fee_course_id' => $data['feeCourseId'] ?: null, 'course_name' => $data['courseName'], 'total_fee' => $data['totalFee'], 'discount' => $data['discount'] ?: 0,
            'installments' => (int) $data['installments'], 'starts_on' => $data['startsOn'] ?: null, 'ends_on' => $data['endsOn'] ?: null, 'notes' => $data['notes'] ?: null];
        if ($this->enrollmentId) {
            $fees->updateEnrollment(TrainingEnrollment::findOrFail($this->enrollmentId), [...$values, 'status' => $data['enrollStatus']]);
            $this->toast('Enrolment updated.');
        } else {
            $fees->enroll(AttendanceEmployee::findOrFail($data['employeeId']), $values, $this->user());
            $this->toast('Trainee enrolled.');
        }
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

    public function editCourse(?int $id = null): void
    {
        $this->resetValidation();
        $c = $id ? TrainingFeeCourse::findOrFail($id) : null;
        $this->courseId = $c?->id;
        $this->cName = (string) $c?->name;
        $this->cDuration = (string) $c?->duration;
        $this->cFee = $c ? (string) (float) $c->fee : '';
        $this->cDescription = (string) $c?->description;
        $this->cActive = $c?->is_active ?? true;
        $this->showCourse = true;
    }

    public function saveCourse(TrainingFeeService $fees): void
    {
        $data = $this->validate(['cName' => ['required', 'string', 'max:255'], 'cDuration' => ['nullable', 'string', 'max:60'], 'cFee' => ['required', 'numeric', 'min:0', 'max:10000000'], 'cDescription' => ['nullable', 'string', 'max:2000']],
            [], ['cName' => 'course name', 'cDuration' => 'duration', 'cFee' => 'fee']);
        $fees->saveCourse($this->courseId ? TrainingFeeCourse::findOrFail($this->courseId) : null, ['name' => $data['cName'], 'duration' => $data['cDuration'] ?: null, 'fee' => $data['cFee'], 'description' => $data['cDescription'] ?: null, 'is_active' => $this->cActive]);
        $this->showCourse = false;
        $this->toast('Course saved.');
    }

    public function closeModal(): void
    {
        $this->showEnroll = false;
        $this->showCourse = false;
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
        $enrollments = TrainingEnrollment::with(['employee.department', 'payments'])
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
            'courses' => TrainingFeeCourse::withCount('enrollments')->orderByDesc('is_active')->orderBy('name')->get(),
            'courseNames' => TrainingEnrollment::distinct()->orderBy('course_name')->pluck('course_name'),
            'trainees' => $this->showEnroll ? AttendanceEmployee::where('is_active', true)->where('employment_type', EmployeeService::TRAINEE)->inCodeOrder()->get(['id', 'name', 'employee_code']) : collect(),
            'paying' => $this->payingId ? TrainingEnrollment::with(['employee', 'payments'])->find($this->payingId) : null,
            'viewing' => $this->viewingId ? TrainingEnrollment::with(['employee', 'payments.receiver'])->find($this->viewingId) : null,
            'isAdmin' => \App\Support\Access::isAdmin($this->user()),
        ], 'Course fees');
    }
}
