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
            $claim = DB::transaction(function () use ($employee, $data, $receipt, $path) {
                $claim = ExpenseClaim::create([
                    'employee_id' => $employee->id, 'category' => $data['category'], 'spent_on' => $data['spent_on'],
                    'amount' => round((float) $data['amount'], 2), 'description' => $data['description'],
                    'receipt_path' => $path, 'receipt_name' => $receipt?->getClientOriginalName(), 'status' => 'pending',
                ]);
                $approver = ApprovalInbox::approverFor($employee); // admins/HR when the manager is unavailable
                ApprovalRequest::create(['approvable_type' => ExpenseClaim::class, 'approvable_id' => $claim->id, 'approver_id' => $approver, 'status' => 'pending']);
                $this->audit->record('expense.submitted', $claim, ['amount' => (float) $claim->amount, 'category' => $claim->category]);

                return $claim;
            });
        } catch (\Throwable $error) {
            if ($path) Storage::disk('local')->delete($path);
            throw $error;
        }
        // The employee's manager AND the expense administrators (HR/Accounts/Admin) are told,
        // so a claim is never stuck with one person.
        $amount = number_format((float) $claim->amount, 2);
        Notifier::send($this->approvers($employee), 'ExpenseClaim', $claim->id, "An expense claim from {$employee->name} ({$claim->category}, {$amount}, spent on {$claim->spent_on->format('d M Y')}) needs review.", route('expenses.index', ['tab' => 'approvals'], false), $employee->user_id);

        return $claim;
    }

    /** People who may decide an employee's claim: their manager and expense administrators. */
    public function approvers(AttendanceEmployee $employee): \Illuminate\Support\Collection
    {
        $manager = ApprovalInbox::approverFor($employee);

        return Notifier::withPermission('expenses.manage')->push($manager ? User::find($manager) : null)->filter()->unique('id')->values();
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
            $by = User::find($approverId)?->name ?? 'Your approver';
            Notifier::send($claim->employee?->user, 'ExpenseClaim', $claim->id, $approve ? "{$by} approved your expense claim of {$amount}; it will be paid with salary." : "{$by} rejected your expense claim of {$amount}: {$note}");

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
        $amount = number_format((float) $claim->amount, 2);
        Notifier::send($this->approvers($employee), 'ExpenseClaim', $claim->id, "{$employee->name} cancelled their expense claim of {$amount} ({$claim->category}, {$claim->spent_on->format('d M Y')}). No action is needed.", route('expenses.index', ['tab' => 'all'], false), $employee->user_id);

        return $claim;
    }

    /**
     * Owner, their manager, expense administrators, and whoever is (or was)
     * the named approver may see a claim and its receipt. Approvers who were
     * notified about a claim used to get "403 Forbidden" when opening it.
     */
    public function canView(User $user, ExpenseClaim $claim): bool
    {
        $employee = $claim->employee;
        if ($user->canAny(['expenses.manage', 'hr.manage']) || $employee?->user_id === $user->id || $employee?->manager?->user_id === $user->id) return true;

        return ApprovalRequest::where('approvable_type', ExpenseClaim::class)->where('approvable_id', $claim->id)->where('approver_id', $user->id)->exists();
    }
}
