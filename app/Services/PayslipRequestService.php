<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\LegacySalaryRecord;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Payslip requests (website and app):
 *  - a month whose payslip this system already made: given at once;
 *  - a month whose payroll is in this system but not paid yet: "not ready yet" (it comes when the salary is paid);
 *  - an older month (before this system, e.g. before October 2026): HR is told and sends it - in one click from the
 *    old salary sheet (Legacy Salary) when that month was imported, or as an uploaded file - or says why not.
 */
class PayslipRequestService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** This system's payslip for the employee and month, if any. */
    public function payslipFor(int $employeeId, string $month): ?Payslip
    {
        [$from, $to] = $this->range($month);

        return Payslip::where('employee_id', $employeeId)->whereHas('item.run', fn ($q) => $q->whereDate('period_starts_on', '<=', $to)->whereDate('period_ends_on', '>=', $from))->latest('id')->first();
    }

    /** The month from the old salary sheet (matched by employee, or by employee code when the row was not linked). */
    public function legacyFor(AttendanceEmployee $employee, string $month): ?LegacySalaryRecord
    {
        return LegacySalaryRecord::where('payroll_month', $month)
            ->where(fn ($q) => $q->where('employee_id', $employee->id)->when($employee->employee_code, fn ($q, $code) => $q->orWhere('employee_code', $code)))
            ->first();
    }

    public function request(AttendanceEmployee $employee, string $month, ?string $note, User $user): PayslipRequest
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) throw ValidationException::withMessages(['month' => 'Choose a month.']);
        if ($month > now()->format('Y-m')) throw ValidationException::withMessages(['month' => 'Choose this month or an earlier one.']);
        $label = Carbon::parse($month.'-01')->format('F Y');
        if (PayslipRequest::where('employee_id', $employee->id)->where('month', $month)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['month' => "You already asked for the {$label} payslip; HR will send it."]);
        }
        $note = trim((string) $note) !== '' ? mb_substr(trim((string) $note), 0, 1000) : null;

        // Made by this system already: given at once.
        if ($payslip = $this->payslipFor($employee->id, $month)) {
            $request = PayslipRequest::create(['employee_id' => $employee->id, 'month' => $month, 'note' => $note, 'status' => 'sent', 'payslip_id' => $payslip->id, 'requested_by' => $user->id, 'decided_at' => now(), 'decision_note' => 'Already available in Payslips.']);
            Notifier::send($user, 'PayslipRequest', $request->id, "Your {$label} payslip ({$payslip->reference}) is ready to open.", route('payroll.payslips', [], false));

            return $request;
        }
        // Payroll of this system, not paid yet.
        [$from, $to] = $this->range($month);
        if (PayrollRun::whereDate('period_starts_on', '<=', $to)->whereDate('period_ends_on', '>=', $from)->whereHas('items', fn ($q) => $q->where('employee_id', $employee->id))->exists()) {
            throw ValidationException::withMessages(['month' => "The {$label} payslip is not ready yet: it is made when that salary is paid. You will be told."]);
        }
        if ($month === now()->format('Y-m')) throw ValidationException::withMessages(['month' => "The {$label} payslip is made after this month's salary is paid."]);

        $request = DB::transaction(fn () => PayslipRequest::create(['employee_id' => $employee->id, 'month' => $month, 'note' => $note, 'status' => 'pending', 'requested_by' => $user->id]));
        $this->audit->record('payslip.requested', $request, ['month' => $month]);
        $legacy = $this->legacyFor($employee, $month);
        Notifier::send(Notifier::withPermission('payroll.manage'), 'PayslipRequest', $request->id, "{$employee->name} asked for the {$label} payslip".($note ? " ({$note})" : '').'. '.($legacy ? 'That month is in Legacy Salary: send it in one click.' : 'Upload the payslip file or say why it cannot be given.'), route('payroll.payslips', ['scope' => 'all'], false), $user->id);
        rescue(fn () => app(TelegramNotificationService::class)->toAdmin('payslip_request', "payslip-request:{$request->id}", TelegramNotificationService::format('Payslip requested', ['Employee' => $employee->name, 'Employee ID' => $employee->employee_code, 'Month' => $label, 'Note' => $note, 'Old salary sheet' => $legacy ? 'Available - send in one click' : 'Not found - upload the file'])), null, true);

        return $request;
    }

    /** HR: send the month from the old salary sheet (shown as a payslip page the employee can print / save as PDF). */
    public function sendFromLegacy(PayslipRequest $request, User $actor): PayslipRequest
    {
        $this->assertPending($request);
        $legacy = $this->legacyFor($request->employee, $request->month) ?? throw ValidationException::withMessages(['request' => 'That month is not in Legacy Salary. Upload the file instead.']);

        return $this->finish($request, $actor, ['status' => 'sent', 'legacy_record_id' => $legacy->id], 'from the old salary sheet');
    }

    /** HR: upload the payslip file (PDF or picture), kept privately. */
    public function sendFile(PayslipRequest $request, UploadedFile $file, User $actor): PayslipRequest
    {
        $this->assertPending($request);
        $path = $file->storeAs('payslip-requests', $request->id.'-'.bin2hex(random_bytes(6)).'.'.strtolower($file->getClientOriginalExtension() ?: 'pdf'), 'local');

        return $this->finish($request, $actor, ['status' => 'sent', 'file_path' => $path], 'as a file');
    }

    public function reject(PayslipRequest $request, string $note, User $actor): PayslipRequest
    {
        $this->assertPending($request);
        if (mb_strlen(trim($note)) < 3) throw ValidationException::withMessages(['note' => 'Say why it cannot be given.']);

        return $this->finish($request, $actor, ['status' => 'rejected', 'decision_note' => mb_substr(trim($note), 0, 1000)], null);
    }

    private function assertPending(PayslipRequest $request): void
    {
        if ($request->fresh()->status !== 'pending') throw ValidationException::withMessages(['request' => 'This request has already been answered.']);
    }

    private function finish(PayslipRequest $request, User $actor, array $changes, ?string $how): PayslipRequest
    {
        $request->update([...$changes, 'decided_by' => $actor->id, 'decided_at' => now()]);
        $this->audit->record('payslip.request_'.$request->status, $request, ['month' => $request->month]);
        $label = $request->monthLabel();
        Notifier::send($request->employee?->user, 'PayslipRequest', $request->id, $request->status === 'sent'
            ? "Your {$label} payslip is ready ({$how}). Open it in Payslips > My requests."
            : "Your {$label} payslip could not be given: {$request->decision_note}", route('payroll.payslips', [], false), $actor->id);

        return $request->fresh();
    }

    /** Who may open it: the employee themself or payroll staff. */
    public static function canOpen(?User $user, PayslipRequest $request): bool
    {
        return $user !== null && ($request->employee?->user_id === $user->id || $user->canAny(['payroll.manage', 'payroll.approve']));
    }

    /** The document: this system's payslip, the old-sheet payslip page, or the uploaded file. */
    public function document(PayslipRequest $request): mixed
    {
        abort_unless($request->hasDocument(), 404);
        if ($request->payslip_id) {
            $payslip = $request->payslip->load(['employee.department', 'item.run', 'item.payments']);

            return view('hr.payslip', compact('payslip'));
        }
        if ($request->file_path) {
            abort_unless(Storage::disk('local')->exists($request->file_path), 404);

            return Storage::disk('local')->response($request->file_path, 'Payslip-'.$request->month.'.'.pathinfo($request->file_path, PATHINFO_EXTENSION));
        }
        $record = $request->legacyRecord;
        abort_unless($record, 404);

        return view('hr.legacy-payslip', ['record' => $record, 'request' => $request->load('employee.department')]);
    }

    /** First and last day of a month 'YYYY-MM'. */
    private function range(string $month): array
    {
        $start = Carbon::parse($month.'-01');

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }
}
