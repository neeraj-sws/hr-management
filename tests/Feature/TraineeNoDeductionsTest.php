<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\EmployeePenalty;
use App\Models\LeaveType;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\HrWorkflowService;
use App\Services\PayslipRequestService;
use App\Services\PeopleOperationsService;
use App\Support\CalendarPay;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Trainees pay a fee and get no salary: no fines of any kind, no salary / adjustment / advance / payslip, no
 * salary or fine pages in their menu, and no rupees in their attendance calendar. Their attendance itself, and
 * notices about check-in, breaks, announcements, holidays and leave, stay as for everyone.
 */
class TraineeNoDeductionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private PeopleOperationsService $people;
    private AttendanceEmployee $trainee;
    private AttendanceEmployee $staff;
    private User $traineeUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->people = app(PeopleOperationsService::class);
        foreach (['attendance.late_penalty' => ['fixed_amount' => 500, 'grace_minutes' => 16], 'attendance.early_checkout' => ['amount_per_minute' => 1, 'grace_minutes' => 0, 'max_amount' => 0],
            'attendance.unauthorized_absence' => ['amount_per_day' => 500, 'also_fine' => 1, 'auto_deduct' => 1], 'leave.late_application' => ['amount' => 300, 'notice_days' => 0]] as $key => $config) {
            $this->people->setPolicy($key, 'compliance', $key, $config, null, $this->admin->id, '2026-01-01');
        }
        $this->traineeUser = User::factory()->create(['email' => 'trainee@company.test']);
        $this->traineeUser->assignRole('Employee');
        $this->trainee = AttendanceEmployee::create(['employee_code' => 'TR-1', 'name' => 'Tahir Trainee', 'employment_type' => 'trainee', 'is_active' => true, 'user_id' => $this->traineeUser->id, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '18:00:00', 'daily_work_hours' => 8]);
        $this->staff = AttendanceEmployee::create(['employee_code' => 'ST-1', 'name' => 'Staff Person', 'is_active' => true, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '18:00:00', 'daily_work_hours' => 8]);
        SalaryProfile::create(['employee_id' => $this->staff->id, 'monthly_salary' => 22000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        // Both: late and early on 13 Oct, absent with no leave on 14 Oct.
        foreach ([$this->trainee, $this->staff] as $e) {
            AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-13', 'check_in' => '2026-10-13 11:00:00', 'check_out' => '2026-10-13 16:00:00', 'status' => 'completed']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fines(AttendanceEmployee $e): int
    {
        return EmployeePenalty::where('employee_id', $e->id)->count();
    }

    public function test_trainees_get_no_fines_of_any_kind(): void
    {
        $this->people->assessCompliance('2026-10-13', '2026-10-14', $this->admin->id);
        $this->assertGreaterThanOrEqual(3, $this->fines($this->staff), 'Staff: late, early check-out and absence fines');
        $this->assertSame(0, $this->fines($this->trainee), 'Trainee: none');

        // Leave asked after the day: a late-application fine for staff, none for the trainee.
        $type = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        foreach ([$this->trainee, $this->staff] as $e) {
            $leave = app(HrWorkflowService::class)->requestLeave($e, ['leave_type_id' => $type->id, 'duration' => 'full', 'starts_on' => Carbon::parse('2026-10-15'), 'ends_on' => Carbon::parse('2026-10-15'), 'reason' => 'Was ill', 'late_reason' => 'Was ill, could not write'], false);
            $this->people->lateLeaveFine($leave);
        }
        $this->assertSame(0, $this->fines($this->trainee));

        // A fine by hand is refused for a trainee.
        try {
            $this->people->requestPenalty($this->trainee, 200, 'Late', $this->admin->id);
            $this->fail('A trainee cannot be fined');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('trainee', $e->getMessage());
        }
        $this->assertSame(0, $this->fines($this->trainee));
    }

    public function test_trainees_get_no_salary_adjustment_advance_or_payslip(): void
    {
        foreach ([
            fn () => $this->people->requestAdjustment($this->trainee, 500, 'bonus', '2026-10-31', 'x', $this->admin->id),
            fn () => $this->people->requestAdvance($this->trainee, 1000, 500, 'x', $this->admin->id),
            fn () => app(PayslipRequestService::class)->request($this->trainee, '2026-09', null, $this->traineeUser),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Refused for a trainee');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('rainee', $e->getMessage());
            }
        }
    }

    public function test_trainee_menu_and_calendar_have_no_salary_or_fines(): void
    {
        $labels = collect(Navigation::for($this->traineeUser))->flatMap(fn ($g) => collect($g['items'])->pluck('route'))->all();
        foreach (['payroll.salary', 'payroll.payslips', 'payroll.advances', 'compliance.penalties'] as $route) $this->assertNotContains($route, $labels);
        $this->assertContains('attendance.my', $labels, 'Their own attendance stays');

        EmployeePenalty::create(['employee_id' => $this->staff->id, 'effective_on' => '2026-10-13', 'amount' => 500, 'reason' => 'Late', 'status' => 'approved']);
        $this->assertSame(['short' => [], 'fines' => [], 'not_paid' => []], CalendarPay::month($this->trainee, Carbon::parse('2026-10-01')));
        $this->assertSame(500.0, CalendarPay::month($this->staff, Carbon::parse('2026-10-01'))['fines']['2026-10-13'][0]['amount']);
    }
}
