<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\EmployeePenalty;
use App\Models\LateArrivalRequest;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\ApprovalInbox;
use App\Services\HrWorkflowService;
use App\Services\LateArrivalService;
use App\Services\PeopleOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Coming late, told in advance (expected check-in + valid reason, before the day starts): management approves or
 * not. Approved and in by the time given: no late fine and no late-hours cut. Later than that: the normal rules.
 */
class LateArrivalRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $manager;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-28 08:30:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->manager = $this->person('MGR-5', 'Team Lead');
        $this->employee = $this->person('EMP-41', 'Late Larry', $this->manager->id);
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 22000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        $people = app(PeopleOperationsService::class);
        $people->setPolicy('attendance.late_penalty', 'compliance', 'Late', ['fixed_amount' => 500, 'grace_minutes' => 16, 'deduct_now' => 1, 'hours_cut' => 1], null, $this->admin->id, '2026-01-01');
        $people->setPolicy('payroll.calculation', 'payroll', 'Payroll', ['working_days_per_month' => 22, 'late_checkin_hours_pay' => true, 'late_checkin_after' => '10:16', 'half_day_pay_from' => '2026-10-01'], null, $this->admin->id, '2026-01-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(string $code, string $name, ?int $managerId = null): AttendanceEmployee
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole('Employee');

        return AttendanceEmployee::create(['employee_code' => $code, 'name' => $name, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'manager_id' => $managerId, 'work_start' => '10:00:00', 'work_end' => '18:00:00', 'daily_work_hours' => 8]);
    }

    private function day(string $in): AttendanceSession
    {
        return AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-28', 'check_in' => "2026-10-28 {$in}:00", 'check_out' => '2026-10-28 18:00:00', 'active_seconds' => 20000, 'status' => 'completed']);
    }

    public function test_approved_and_in_by_the_time_given_means_no_late_fine_or_cut(): void
    {
        $late = app(LateArrivalService::class);
        $request = $late->request($this->employee, '2026-10-28', '11:30', 'Bank visit for the company account');
        $this->assertSame('pending', $request->status);
        $this->assertStringContainsString('Late arrival of Late Larry needs review', $this->manager->user->notifications()->first()->data['message']);
        app(ApprovalInbox::class)->decide($this->manager->user, ApprovalRequest::where('approvable_type', LateArrivalRequest::class)->firstOrFail(), true);

        $session = $this->day('11:20');
        $this->assertNull(app(HrWorkflowService::class)->lateCheckInPay($this->employee, $session), 'Not cut for coming late');
        app(PeopleOperationsService::class)->assessCompliance('2026-10-28', '2026-10-28', $this->admin->id);
        $this->assertSame(0, EmployeePenalty::count(), 'No late fine');
    }

    public function test_later_than_the_time_given_or_not_approved_follows_the_normal_rules(): void
    {
        $late = app(LateArrivalService::class);
        $late->request($this->employee, '2026-10-28', '11:00', 'Doctor appointment in the morning');
        app(ApprovalInbox::class)->decide($this->manager->user, ApprovalRequest::where('approvable_type', LateArrivalRequest::class)->firstOrFail(), true);
        $session = $this->day('11:45'); // later than 11:00
        $this->assertNotNull(app(HrWorkflowService::class)->lateCheckInPay($this->employee, $session));
        app(PeopleOperationsService::class)->assessCompliance('2026-10-28', '2026-10-28', $this->admin->id);
        $this->assertSame(500.0, (float) EmployeePenalty::value('amount'));
    }

    public function test_must_be_in_advance_and_a_personal_reason_is_rejected(): void
    {
        $late = app(LateArrivalService::class);
        Carbon::setTestNow('2026-10-28 10:05:00'); // after the 10:00 start
        try {
            $late->request($this->employee, '2026-10-28', '11:00', 'Traffic jam on the highway today');
            $this->fail('Should be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('must be sent before 10:00 AM', $e->errors()['work_date'][0]);
        }
        $request = $late->request($this->employee, '2026-10-29', '11:00', 'Personal work at home in the morning');
        $this->assertSame('rejected', $request->status);
        $this->assertSame(0, ApprovalRequest::where('approvable_type', LateArrivalRequest::class)->count());
    }

    public function test_app_late_arrival_screen_and_website_page(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->employee->user, ['*']);
        $id = $this->postJson('/api/late-arrival', ['work_date' => '2026-10-29', 'expected_at' => '11:30', 'reason' => 'Passport office appointment'])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->getJson('/api/late-arrival?month=2026-10')->assertOk()->assertJsonPath('data.0.expected_at', '11:30');
        $this->postJson("/api/late-arrival/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->actingAs($this->employee->user)->get(route('leave.late-arrival'))->assertOk()->assertSee('I will come late');
    }
}
