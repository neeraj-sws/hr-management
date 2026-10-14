<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\HrWorkflowService;
use App\Services\ImportService;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Leave durations, manager task access, resilient correction notify, and CSV imports. */
class Oct13FeaturesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $manager;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-07 10:00:00'); // Wednesday
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $managerUser = User::factory()->create(['email' => 'o13.mgr@example.com']);
        $managerUser->assignRole('Employee');
        $this->manager = AttendanceEmployee::create(['employee_code' => 'O13-MGR', 'name' => 'Mia Manager', 'is_active' => true, 'user_id' => $managerUser->id, 'joined_at' => '2026-01-01']);
        $employeeUser = User::factory()->create(['email' => 'o13.emp@example.com']);
        $employeeUser->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'O13-EMP', 'name' => 'Ravi Report', 'is_active' => true, 'user_id' => $employeeUser->id, 'manager_id' => $this->manager->id, 'work_start' => '09:30:00', 'joined_at' => '2026-01-01', 'monthly_salary' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_half_day_and_short_leave_store_fractional_days_on_a_single_day(): void
    {
        $workflow = app(HrWorkflowService::class);
        $annual = LeaveType::where('name', 'Annual Leave')->firstOrFail();

        $half = $workflow->requestLeave($this->employee, ['leave_type_id' => $annual->id, 'duration' => 'half', 'starts_on' => Carbon::parse('2026-10-14'), 'ends_on' => Carbon::parse('2026-10-14'), 'reason' => 'Half day for errand']);
        $this->assertSame('half', $half->duration);
        $this->assertEquals(0.5, (float) $half->days);

        $short = $workflow->requestLeave($this->employee, ['leave_type_id' => $annual->id, 'duration' => 'short', 'starts_on' => Carbon::parse('2026-10-16'), 'ends_on' => Carbon::parse('2026-10-16'), 'reason' => 'Short leave, two hours']);
        $this->assertEquals(0.25, (float) $short->days);
    }

    public function test_half_day_leave_rejects_a_multi_day_range(): void
    {
        $annual = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(HrWorkflowService::class)->requestLeave($this->employee, ['leave_type_id' => $annual->id, 'duration' => 'half', 'starts_on' => Carbon::parse('2026-10-14'), 'ends_on' => Carbon::parse('2026-10-15'), 'reason' => 'Wrong range']);
    }

    public function test_full_day_multi_day_leave_still_counts_working_days(): void
    {
        $annual = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        $leave = app(HrWorkflowService::class)->requestLeave($this->employee, ['leave_type_id' => $annual->id, 'duration' => 'full', 'starts_on' => Carbon::parse('2026-10-14'), 'ends_on' => Carbon::parse('2026-10-16'), 'reason' => 'Three days off']);
        $this->assertSame('full', $leave->duration);
        $this->assertEquals(3, (float) $leave->days); // Wed–Fri
    }

    public function test_an_employee_with_a_report_is_a_manager_for_access(): void
    {
        $this->assertTrue(Access::allows($this->manager->user, ['manager']), 'A team lead passes the manager access rule');
        $loneUser = User::factory()->create(['email' => 'o13.lone@example.com']);
        $loneUser->assignRole('Employee');
        AttendanceEmployee::create(['employee_code' => 'O13-LONE', 'name' => 'Lone Wolf', 'is_active' => true, 'user_id' => $loneUser->id]);
        $this->assertFalse(Access::allows($loneUser, ['manager']), 'An employee with no reports is not a manager');
    }

    public function test_assigning_a_manager_notifies_them(): void
    {
        $newManagerUser = User::factory()->create(['email' => 'o13.newmgr@example.com']);
        $newManagerUser->assignRole('Employee');
        $newManager = AttendanceEmployee::create(['employee_code' => 'O13-NEWMGR', 'name' => 'Neo Manager', 'is_active' => true, 'user_id' => $newManagerUser->id, 'joined_at' => '2026-01-01']);

        app(\App\Services\EmployeeService::class)->update($this->employee, ['manager_id' => $newManager->id], $this->admin);

        $this->assertTrue($newManagerUser->notifications()->where('data->kind', 'ManagerAssigned')->exists(), 'The new manager is told the employee reports to them');
    }

    public function test_salary_csv_import_creates_profiles_and_updates_the_employee(): void
    {
        $csv = "employee_code,monthly_salary,effective_from,working_days_per_month,standard_daily_hours\n"
            ."O13-EMP,45000,2026-02-01,22,8\n"
            ."O13-EMP,50000,2026-09-01,22,8\n"
            ."NO-SUCH,99999,2026-02-01,22,8\n";
        $result = app(ImportService::class)->salary(UploadedFile::fake()->createWithContent('salary.csv', $csv), $this->admin->id);

        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(2, SalaryProfile::where('employee_id', $this->employee->id)->count());
        $this->assertEquals(50000, (float) $this->employee->fresh()->monthly_salary); // latest profile
    }

    public function test_attendance_csv_import_creates_sessions(): void
    {
        $csv = "employee_code,work_date,check_in,check_out,break_minutes\n"
            ."O13-EMP,2026-09-14,09:30,18:30,30\n"
            ."O13-EMP,2026-09-15,10:00,08:30,0\n"; // overnight: out rolls to next morning
        $result = app(ImportService::class)->attendance(UploadedFile::fake()->createWithContent('att.csv', $csv), $this->admin->id);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['skipped']);
        $session = AttendanceSession::where('employee_id', $this->employee->id)->whereDate('work_date', '2026-09-14')->firstOrFail();
        $this->assertSame('completed', $session->status);
        $this->assertSame((9 * 3600) - (30 * 60) - 0, (int) $session->active_seconds); // 9h minus 30m break
    }

    public function test_import_reports_a_missing_required_column(): void
    {
        $result = app(ImportService::class)->salary(UploadedFile::fake()->createWithContent('bad.csv', "name,amount\nAsha,1000\n"), $this->admin->id);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('Missing required column', $result['errors'][0]);
    }
}
