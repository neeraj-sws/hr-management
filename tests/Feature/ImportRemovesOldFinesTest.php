<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\EmployeePenalty;
use App\Models\PayrollRun;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\ImportService;
use App\Services\PeopleOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A day fined as "absent with no leave request" before its attendance was in HRMS: importing that day's attendance
 * later takes the fine back. The import never makes new fines (it is history).
 */
class ImportRemovesOldFinesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'EMP-17', 'name' => 'Safai wali aunty', 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'work_start' => '09:00:00', 'work_end' => '19:00:00', 'daily_work_hours' => 8]);
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 20000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 25, 'standard_daily_hours' => 8]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function absenceFine(string $date): EmployeePenalty
    {
        return EmployeePenalty::create(['employee_id' => $this->employee->id, 'effective_on' => $date, 'amount' => 500, 'status' => 'approved', 'requested_by' => $this->admin->id,
            'reason' => 'Absent on '.Carbon::parse($date)->format('d M').' with no leave request (no leave mail)', 'source_key' => "absence:{$this->employee->id}:{$date}:1"]);
    }

    private function import(string $csv, bool $intoLocked = false): array
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "Employee Code,Date,Status,In Time,Out Time\n".$csv);

        return app(ImportService::class)->attendance(new UploadedFile($path, 'attendance.csv', 'text/csv', null, true), $this->admin->id, false, null, $intoLocked);
    }

    public function test_importing_a_present_day_removes_its_absent_fine(): void
    {
        $present = $this->absenceFine('2026-10-30');
        $absent = $this->absenceFine('2026-10-29');

        $result = $this->import("EMP-17,30/10/2026,Present,9:15 AM,10:15 AM\nEMP-17,29/10/2026,Absent,,\n");

        $this->assertSame(1, $result['created']);
        $this->assertSame('waived', $present->fresh()->status, 'The day is present now: the absent fine is taken back');
        $this->assertStringContainsString('No longer applies: attendance for 30 Oct 2026 was imported', $present->fresh()->decision_note);
        $this->assertSame('approved', $absent->fresh()->status, 'Still absent in the file: the fine stays');
        $this->assertStringContainsString('1 fine(s) made before this attendance was imported no longer apply and were removed: EMP-17 30 Oct 2026 (Rs 500.00)', implode(' ', $result['notes']));
    }

    public function test_a_fine_in_a_locked_payroll_stays_and_the_import_says_so(): void
    {
        $fine = $this->absenceFine('2026-10-30');
        PayrollRun::create(['period_starts_on' => '2026-10-01', 'period_ends_on' => '2026-10-31', 'status' => 'locked', 'created_by' => $this->admin->id]);

        $result = $this->import("EMP-17,30/10/2026,Present,9:15 AM,10:15 AM\n", true);

        $this->assertSame(1, $result['created']);
        $this->assertSame('approved', $fine->fresh()->status);
        $this->assertStringContainsString('1 fine(s) no longer apply but their payroll is approved / locked, so they stay: EMP-17 30 Oct 2026', implode(' ', $result['notes']));
    }

    public function test_the_import_makes_no_new_fines(): void
    {
        app(PeopleOperationsService::class)->setPolicy('attendance.late_penalty', 'compliance', 'Late', ['amount_per_minute' => 0, 'grace_minutes' => 15, 'max_amount' => 0, 'fixed_amount' => 500, 'deduct_now' => 1], null, $this->admin->id, '2026-01-01');

        $result = $this->import("EMP-17,27/10/2026,Present,11:30 AM,7:00 PM\n");

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, EmployeePenalty::where('employee_id', $this->employee->id)->count());
    }
}
