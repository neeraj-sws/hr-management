<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\AttendanceEmployee;
use App\Models\ExpenseClaim;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Expense claims: employee submits (with an optional receipt), manager or
 * HR/Accounts approves, and approved claims are reimbursed in the next payroll.
 */
class ExpenseService
{
    public const RECEIPT_RULES = ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp,heic'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function submit(AttendanceEmployee $employee, array $data, ?UploadedFile $receipt = null): ExpenseClaim
    {
        if (now()->parse($data['spent_on'])->gt(today())) throw ValidationException::withMessages(['spent_on' => 'The expense date cannot be in the future.']);
        $path = $receipt?->store('expense-receipts/'.$employee->id, 'local');

        try {
            return DB::transaction(function () use ($employee, $data, $receipt, $path) {
                $claim = ExpenseClaim::create([
                    'employee_id' => $employee->id, 'category' => $data['category'], 'spent_on' => $data['spent_on'],
                    'amount' => round((float) $data['amount'], 2), 'description' => $data['description'],
                    'receipt_path' => $path, 'receipt_name' => $receipt?->getClientOriginalName(), 'status' => 'pending',
                ]);
                $approver = $employee->manager?->user_id;
                ApprovalRequest::create(['approvable_type' => ExpenseClaim::class, 'approvable_id' => $claim->id, 'approver_id' => $approver, 'status' => 'pending']);
                $notification = new ApprovalRequested('ExpenseClaim', $claim->id, "An expense claim from {$employee->name} needs review.");
                foreach ($approver ? User::whereKey($approver)->get() : User::permission('expenses.manage')->get() as $user) $user->notify($notification);
                $this->audit->record('expense.submitted', $claim, ['amount' => (float) $claim->amount, 'category' => $claim->category]);

                return $claim;
            });
        } catch (\Throwable $error) {
            if ($path) Storage::disk('local')->delete($path);
            throw $error;
        }
    }

    public function decide(ExpenseClaim $claim, int $approverId, bool $approve, ?string $note = null): ExpenseClaim
    {
        return DB::transaction(function () use ($claim, $approverId, $approve, $note) {
            $claim = ExpenseClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($claim->status !== 'pending') throw ValidationException::withMessages(['claim' => 'This claim has already been decided.'])->status(409);
            if (! $approve && ! trim((string) $note)) throw ValidationException::withMessages(['decisionNote' => 'Add a reason when rejecting.']);
            $claim->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $approverId, 'decided_at' => now(), 'decision_note' => $note]);
            ApprovalRequest::where('approvable_type', ExpenseClaim::class)->where('approvable_id', $claim->id)->where('status', 'pending')
                ->update(['approver_id' => $approverId, 'status' => $approve ? 'approved' : 'rejected', 'decided_at' => now(), 'comment' => $note]);
            $this->audit->record($approve ? 'expense.approved' : 'expense.rejected', $claim);
            $amount = number_format((float) $claim->amount, 2);
            $claim->employee?->user?->notify(new ApprovalRequested('ExpenseClaim', $claim->id, $approve ? "Your expense claim of {$amount} was approved and will be paid with salary." : "Your expense claim of {$amount} was rejected."));

            return $claim->fresh();
        });
    }

    public function cancel(ExpenseClaim $claim, AttendanceEmployee $employee): ExpenseClaim
    {
        if ($claim->employee_id !== $employee->id) abort(403);
        if ($claim->status !== 'pending') throw ValidationException::withMessages(['claim' => 'Only pending claims can be cancelled.']);
        $claim->update(['status' => 'cancelled', 'decided_at' => now()]);
        ApprovalRequest::where('approvable_type', ExpenseClaim::class)->where('approvable_id', $claim->id)->where('status', 'pending')->update(['status' => 'cancelled', 'decided_at' => now()]);
        $this->audit->record('expense.cancelled', $claim);

        return $claim;
    }

    /** Owner, their manager, and expense managers may see a claim and its receipt. */
    public function canView(User $user, ExpenseClaim $claim): bool
    {
        $employee = $claim->employee;

        return $user->canAny(['expenses.manage', 'hr.manage']) || $employee?->user_id === $user->id || $employee?->manager?->user_id === $user->id;
    }
}
