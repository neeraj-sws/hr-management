<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A trainee enrolled in a course: the fee they owe, in how many installments, and what they have paid. */
class TrainingEnrollment extends Model
{
    use Concerns\BelongsToEmployeeCompany;
    public const STATUSES = ['active' => 'Studying', 'completed' => 'Completed', 'dropped' => 'Left the course'];

    protected $fillable = ['employee_id', 'fee_course_id', 'training_course_id', 'course_name', 'total_fee', 'discount', 'installments', 'schedule', 'starts_on', 'ends_on', 'status', 'notes', 'created_by'];
    protected $casts = ['total_fee' => 'decimal:2', 'discount' => 'decimal:2', 'starts_on' => 'date', 'ends_on' => 'date', 'schedule' => 'array'];

    /**
     * Equal monthly installments of the payable fee from the start date (the last one takes the rounding paisa):
     * ₹17,000 in 3 = 5,666.67 + 5,666.67 + 5,666.66, due on the start date, a month later, two months later.
     *
     * @return list<array{amount: float, due_on: string}>
     */
    public static function equalPlan(float $payable, int $count, string $firstDue): array
    {
        $count = max(1, $count);
        $each = round($payable / $count, 2);
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $amount = $i === $count - 1 ? round($payable - $each * ($count - 1), 2) : $each;
            $rows[] = ['amount' => round($amount, 2), 'due_on' => \Illuminate\Support\Carbon::parse($firstDue)->addMonthsNoOverflow($i)->toDateString()];
        }

        return $rows;
    }

    /** The plan in use: what HR saved (when it still fits the fee and the installment count), else equal monthly installments. */
    public function plan(): array
    {
        $saved = array_values(array_filter((array) ($this->schedule ?? []), fn ($r) => is_array($r) && isset($r['amount'], $r['due_on'])));
        $fits = count($saved) === max(1, (int) $this->installments) && abs(array_sum(array_column($saved, 'amount')) - $this->payable()) < 0.01;

        return $fits ? $saved : self::equalPlan($this->payable(), (int) $this->installments, ($this->starts_on ?? $this->created_at ?? now())->toDateString());
    }

    /**
     * Each installment with what is paid towards it (payments fill the installments in order) and its status:
     * paid, part paid, overdue (due date passed), due today, or upcoming.
     *
     * @return list<array{no: int, amount: float, due_on: string, paid: float, left: float, status: string}>
     */
    public function installmentRows(): array
    {
        $pool = $this->paid();
        $today = today()->toDateString();
        $rows = [];
        foreach ($this->plan() as $i => $r) {
            $amount = round((float) $r['amount'], 2);
            $paid = round(min($amount, max(0, $pool)), 2);
            $pool = round($pool - $paid, 2);
            $left = round($amount - $paid, 2);
            $status = $left <= 0.001 ? 'paid' : ($r['due_on'] < $today ? 'overdue' : ($r['due_on'] === $today ? 'due_today' : ($paid > 0 ? 'part_paid' : 'upcoming')));
            $rows[] = ['no' => $i + 1, 'amount' => $amount, 'due_on' => $r['due_on'], 'paid' => $paid, 'left' => $left, 'status' => $status];
        }

        return $rows;
    }

    /** The first installment not fully paid, or null when the fee is paid. */
    public function nextDue(): ?array
    {
        return collect($this->installmentRows())->first(fn ($r) => $r['left'] > 0.001);
    }

    public const INSTALLMENT_STATUSES = ['paid' => ['Paid', 'green'], 'part_paid' => ['Part paid', 'amber'], 'overdue' => ['Overdue', 'red'], 'due_today' => ['Due today', 'amber'], 'upcoming' => ['Upcoming', 'gray']];

    public function employee()
    {
        return $this->belongsTo(AttendanceEmployee::class);
    }

    /** The course from the one course list (Training > Courses). */
    public function course()
    {
        return $this->belongsTo(TrainingCourse::class, 'training_course_id');
    }

    /** The course's topics for this trainee, in order, each with its mentor and progress. */
    public function topics()
    {
        return $this->hasMany(TrainingEnrollmentTopic::class, 'enrollment_id')->orderBy('sort')->orderBy('id');
    }

    /** The topic being taught now (or null when none has started or all are done). */
    public function currentTopic(): ?TrainingEnrollmentTopic
    {
        return ($this->relationLoaded('topics') ? $this->topics : $this->topics()->get())->firstWhere('status', 'active');
    }

    public function payments()
    {
        return $this->hasMany(TrainingFeePayment::class, 'enrollment_id')->orderBy('paid_on')->orderBy('id');
    }

    /** What the trainee has to pay: the fee less the discount. */
    public function payable(): float
    {
        return round(max(0, (float) $this->total_fee - (float) $this->discount), 2);
    }

    public function paid(): float
    {
        return round((float) ($this->relationLoaded('payments') ? $this->payments->sum('amount') : $this->payments()->sum('amount')), 2);
    }

    public function balance(): float
    {
        return round(max(0, $this->payable() - $this->paid()), 2);
    }

    /** The next installment: what is left spread over the installments not yet paid. */
    public function nextInstallment(): float
    {
        $count = $this->relationLoaded('payments') ? $this->payments->count() : $this->payments()->count();
        $left = max(1, (int) $this->installments - $count);

        return round($this->balance() / $left, 2);
    }
}
