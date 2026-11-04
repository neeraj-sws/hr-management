<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\TrainingEnrollment;
use App\Models\TrainingFeeCourse;
use App\Models\TrainingFeePayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Course fees paid by trainees: enrolments, installments and payments, each one audited. */
class TrainingFeeService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function saveCourse(?TrainingFeeCourse $course, array $data): TrainingFeeCourse
    {
        $course ??= new TrainingFeeCourse;
        $course->fill($data)->save();
        $this->audit->record('training.fee_course_saved', $course, ['name' => $course->name, 'fee' => (float) $course->fee]);

        return $course;
    }

    public function enroll(AttendanceEmployee $employee, array $data, User $actor): TrainingEnrollment
    {
        if ((float) ($data['discount'] ?? 0) > (float) $data['total_fee']) throw ValidationException::withMessages(['discount' => 'The discount cannot be more than the fee.']);
        // The one course list (Training > Courses).
        $course = ! empty($data['training_course_id']) ? \App\Models\TrainingCourse::find($data['training_course_id']) : null;
        $enrollment = TrainingEnrollment::create([
            'employee_id' => $employee->id, 'training_course_id' => $course?->id, 'course_name' => $course?->title ?? $data['course_name'],
            'total_fee' => $data['total_fee'], 'discount' => $data['discount'] ?? 0, 'installments' => max(1, (int) ($data['installments'] ?? 1)), 'schedule' => $data['schedule'] ?? null,
            'starts_on' => $data['starts_on'] ?? null, 'ends_on' => $data['ends_on'] ?? null, 'status' => 'active', 'notes' => $data['notes'] ?? null, 'created_by' => $actor->id,
        ]);
        $this->audit->record('training.enrolled', $enrollment, ['employee' => $employee->name, 'course' => $enrollment->course_name, 'fee' => $enrollment->payable()]);
        // The trainee is told the course, the fee and the first installment.
        $first = $enrollment->installmentRows()[0] ?? null;
        Notifier::send($employee->user, 'TrainingEnrollment', $enrollment->id, "You are enrolled in {$enrollment->course_name}: fee ".self::rs($enrollment->payable())
            .((float) $enrollment->discount > 0 ? ' (after a discount of '.self::rs((float) $enrollment->discount).')' : '').', '.$enrollment->installments.' '.($enrollment->installments == 1 ? 'payment' : 'installments')
            .($first ? '; first '.self::rs($first['amount']).' due on '.Carbon::parse($first['due_on'])->format('d M Y') : '').'.', route('training.my-fees', [], false), $actor->id);

        return $enrollment;
    }

    public function updateEnrollment(TrainingEnrollment $enrollment, array $data): TrainingEnrollment
    {
        if ((float) ($data['discount'] ?? 0) > (float) $data['total_fee']) throw ValidationException::withMessages(['discount' => 'The discount cannot be more than the fee.']);
        $enrollment->update($data);
        if ($enrollment->paid() > $enrollment->payable()) throw ValidationException::withMessages(['total_fee' => 'More has already been paid ('.number_format($enrollment->paid(), 2).') than this fee.']);
        $this->audit->record('training.enrollment_updated', $enrollment, ['course' => $enrollment->course_name, 'fee' => $enrollment->payable(), 'status' => $enrollment->status]);

        return $enrollment;
    }

    /** Records a payment (never more than what is still due) with the next receipt number, e.g. FEE-2026-0007. */
    public function recordPayment(TrainingEnrollment $enrollment, array $data, User $actor): TrainingFeePayment
    {
        return DB::transaction(function () use ($enrollment, $data, $actor) {
            $enrollment = TrainingEnrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) throw ValidationException::withMessages(['amount' => 'Enter the amount received.']);
            if ($amount > $enrollment->balance() + 0.001) throw ValidationException::withMessages(['amount' => 'Only '.number_format($enrollment->balance(), 2).' is still due on this course.']);
            $year = substr((string) $data['paid_on'], 0, 4);
            $last = TrainingFeePayment::where('receipt_no', 'like', "FEE-{$year}-%")->lockForUpdate()->orderByDesc('receipt_no')->value('receipt_no');
            $receipt = sprintf('FEE-%s-%04d', $year, $last ? ((int) substr($last, -4)) + 1 : 1);
            $payment = TrainingFeePayment::create([
                'enrollment_id' => $enrollment->id, 'employee_id' => $enrollment->employee_id, 'receipt_no' => $receipt, 'amount' => $amount,
                'paid_on' => $data['paid_on'], 'method' => $data['method'], 'reference' => $data['reference'] ?? null,
                'installment_no' => $enrollment->payments()->count() + 1, 'note' => $data['note'] ?? null, 'received_by' => $actor->id,
            ]);
            if ($enrollment->fresh()->balance() <= 0.001 && $enrollment->status === 'active' && $enrollment->ends_on && $enrollment->ends_on->isPast()) $enrollment->update(['status' => 'completed']);
            $this->audit->record('training.fee_paid', $payment, ['receipt' => $receipt, 'amount' => $amount, 'method' => $payment->method, 'course' => $enrollment->course_name]);
            // The trainee is told what was received, what is left and the next installment.
            $fresh = $enrollment->fresh('payments');
            $next = $fresh->nextDue();
            Notifier::send($fresh->employee?->user, 'TrainingFeePayment', $payment->id, 'Received '.self::rs($amount)." for {$fresh->course_name} (receipt {$receipt}). Paid ".self::rs($fresh->paid()).' of '.self::rs($fresh->payable()).'; '
                .($fresh->balance() > 0 ? 'still due '.self::rs($fresh->balance()).($next ? ', next '.self::rs($next['left']).' due on '.Carbon::parse($next['due_on'])->format('d M Y') : '').'.' : 'the fee is fully paid. Thank you!'), route('training.my-fees', [], false), $actor->id);

            return $payment;
        });
    }

    private static function rs(float $amount): string
    {
        return '₹'.number_format($amount, 2);
    }

    /**
     * Daily reminders of course-fee installments: 3 days before the due date, on the due date, and once when it is
     * overdue (to HR as well). Each reminder is sent once per installment (remembered in the plan).
     *
     * @return int reminders sent
     */
    public function sendReminders(): int
    {
        $sent = 0;
        $today = today();
        foreach (TrainingEnrollment::with(['payments', 'employee.user'])->where('status', 'active')->get() as $enrollment) {
            if (! $enrollment->employee?->user || $enrollment->balance() <= 0.001) continue;
            $plan = $enrollment->plan();
            $changed = false;
            foreach ($enrollment->installmentRows() as $i => $row) {
                if ($row['left'] <= 0.001) continue;
                $due = Carbon::parse($row['due_on']);
                $kind = $due->isSameDay($today->copy()->addDays(3)) ? 'soon' : ($due->isSameDay($today) ? 'due' : ($due->lt($today) ? 'overdue' : null));
                if (! $kind || ! empty($plan[$i]['reminded'][$kind])) continue;
                $what = 'installment #'.$row['no'].' of '.self::rs($row['left'])." for {$enrollment->course_name}";
                $message = match ($kind) {
                    'soon' => "Reminder: your {$what} is due on ".$due->format('d M Y').' (in 3 days).',
                    'due' => "Your {$what} is due today (".$due->format('d M Y').').',
                    'overdue' => "Your {$what} was due on ".$due->format('d M Y').' and is not paid yet. Please pay it soon.',
                };
                Notifier::send($enrollment->employee->user, 'TrainingFeeReminder', $enrollment->id, $message, route('training.my-fees', [], false));
                if ($kind === 'overdue') {
                    Notifier::send(Notifier::withPermission('training.manage'), 'TrainingFeeReminder', $enrollment->id, "{$enrollment->employee->name}: {$what} was due on ".$due->format('d M Y').' and is not paid yet.', route('training.fees', [], false));
                }
                $plan[$i]['reminded'][$kind] = $today->toDateString();
                $changed = true;
                $sent++;
            }
            if ($changed) $enrollment->forceFill(['schedule' => $plan])->saveQuietly();
        }

        return $sent;
    }

    /** Removes a payment entered by mistake (administrators only); audited with its details. */
    public function deletePayment(TrainingFeePayment $payment, User $actor): void
    {
        abort_unless(\App\Support\Access::isAdmin($actor), 403, 'Only an administrator can remove a payment.');
        $this->audit->record('training.fee_payment_removed', $payment->enrollment, ['receipt' => $payment->receipt_no, 'amount' => (float) $payment->amount, 'paid_on' => $payment->paid_on->toDateString(), 'removed_by' => $actor->name]);
        $payment->delete();
    }
}
