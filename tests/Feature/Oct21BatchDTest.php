<?php

namespace Tests\Feature;

use App\Livewire\People\EmployeeProfile;
use App\Livewire\People\MyTeam;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\EmployeeService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** 21 Oct batch D: every day in the attendance report, code order, My team page, readable history, compliance filters. */
class Oct21BatchDTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-21 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function employee(string $code, array $extra = []): AttendanceEmployee
    {
        $user = User::factory()->create(['email' => strtolower($code).'@example.com']);
        $user->assignRole('Employee');

        return AttendanceEmployee::create(['employee_code' => $code, 'name' => "Person {$code}", 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '19:00:00', ...$extra]);
    }

    public function test_attendance_report_lists_every_day_in_employee_code_order_with_a_day_filter(): void
    {
        $b = $this->employee('EMP-10');
        $a = $this->employee('EMP-2');
        AttendanceSession::create(['employee_id' => $a->id, 'work_date' => '2026-10-19', 'check_in' => '2026-10-19 10:00:00', 'check_out' => '2026-10-19 19:00:00', 'active_seconds' => 32400, 'status' => 'completed']);
        LeaveRequest::create(['employee_id' => $a->id, 'leave_type_id' => LeaveType::firstOrFail()->id, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20', 'days' => 1, 'duration' => 'full', 'reason' => 'Fever', 'status' => 'approved']);
        $report = app(ReportService::class);
        $filters = ['from' => '2026-10-17', 'to' => '2026-10-21'];

        $rows = $report->rows($this->admin, 'attendance', $filters)->whereIn('employee_code', ['EMP-2', 'EMP-10'])->values();
        // 17–20 Oct; today (21st) is not over and nobody checked in yet, so it is not listed.
        $this->assertSame(['EMP-2', 'EMP-2', 'EMP-2', 'EMP-2', 'EMP-10', 'EMP-10', 'EMP-10', 'EMP-10'], $rows->pluck('employee_code')->all(), 'Every day, EMP-2 before EMP-10');
        $mine = $rows->where('employee_code', 'EMP-2')->keyBy('work_date');
        $this->assertSame(['weekend', 'weekend', 'present', 'on_leave'], $mine->pluck('day_status')->values()->all());
        $this->assertSame('absent', $rows->firstWhere('employee_code', 'EMP-10')['day_status'] === 'weekend' ? $rows->where('employee_code', 'EMP-10')->last()['day_status'] : null);
        $this->assertSame('Sat', $mine['2026-10-17']['day']);

        $leaveOnly = $report->rows($this->admin, 'attendance', [...$filters, 'day_status' => 'on_leave'])->whereIn('employee_code', ['EMP-2', 'EMP-10']);
        $this->assertSame(['2026-10-20'], $leaveOnly->pluck('work_date')->all());
        $this->assertSame([], $report->rows($this->admin, 'attendance', [...$filters, 'day_status' => 'present', 'employee_id' => $b->id])->all());
    }

    public function test_managers_get_a_my_team_page_and_others_do_not(): void
    {
        $lead = $this->employee('MT-1');
        $this->employee('MT-3', ['manager_id' => $lead->id, 'designation' => 'Tester']);
        $this->employee('MT-2', ['manager_id' => $lead->id, 'designation' => 'Developer']);

        $this->actingAs($lead->user)->get(route('people.my-team'))->assertOk();
        Livewire::actingAs($lead->user)->test(MyTeam::class)->assertSeeInOrder(['Person MT-2', 'Person MT-3'])->assertSee('Not checked in');
        $this->actingAs(AttendanceEmployee::where('employee_code', 'MT-2')->first()->user)->get(route('people.my-team'))->assertForbidden();
        $this->actingAs($lead->user)->get(route('dashboard'))->assertDontSee('Everyone who reports to you');
    }

    public function test_history_says_what_changed_in_plain_words(): void
    {
        $e = $this->employee('HI-1', ['designation' => 'Developer']);
        app(EmployeeService::class)->update($e, ['designation' => 'Web Designer', 'daily_work_hours' => 8.5], $this->admin);
        app(\App\Services\ScreenCaptureService::class)->setForEmployee($e, true, $this->admin);

        $updated = AuditLog::where('action', 'employee.updated')->latest('id')->first()->describe();
        $this->assertSame('Profile updated', $updated['title']);
        $this->assertStringContainsString('Designation: Developer → Web Designer', $updated['detail']);
        $this->assertSame('Screen capture turned on', AuditLog::where('action', 'screen_capture.enabled_for_employee')->latest('id')->first()->describe()['title']);

        Livewire::actingAs($this->admin)->test(EmployeeProfile::class, ['employee' => $e])
            ->assertSee('Designation: Developer → Web Designer')->assertSee('Screen capture turned on');
    }

    public function test_compliance_history_filters_by_month_and_type(): void
    {
        $e = $this->employee('CP-1');
        AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-09-15', 'check_in' => '2026-09-15 10:45:00', 'check_out' => '2026-09-15 19:00:00', 'status' => 'completed']);
        AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-14', 'check_in' => '2026-10-14 10:00:00', 'check_out' => '2026-10-14 17:00:00', 'status' => 'completed']);

        $page = Livewire::actingAs($this->admin)->test(EmployeeProfile::class, ['employee' => $e]);
        $this->assertCount(4, $page->viewData('discipline'), 'Last 90 days: late + 2 missing statuses + early');
        $page->set('complianceMonth', '2026-09');
        $this->assertSame(['late', 'no_status'], $page->viewData('discipline')->pluck('type')->sort()->values()->all());
        $page->set('complianceMonth', '')->set('complianceType', 'early');
        $this->assertSame(['2026-10-14'], $page->viewData('discipline')->pluck('date')->all());
    }

    public function test_payments_report_lists_unpaid_old_sheet_months_by_salary_month(): void
    {
        $e = $this->employee('PAY-1');
        $row = fn (string $month, array $extra) => \App\Models\LegacySalaryRecord::create(['employee_id' => $e->id, 'employee_code' => 'PAY-1', 'payroll_month' => $month, 'name' => $e->name, 'name_key' => strtolower($e->name), ...$extra]);
        $row('2025-04', ['salary_paid' => 9000, 'salary_remains' => 0, 'payment_date' => '2025-05-09', 'payment_status' => 'Paid']);
        $row('2025-06', ['total_salary_with_bonus' => 9500, 'salary_paid' => 0]); // never paid: no payment date

        $rows = app(ReportService::class)->rows($this->admin, 'payments', ['from' => '2025-04-01', 'to' => '2025-12-31', 'employee_id' => $e->id]);
        $this->assertSame(['Jun 2025 (old salary sheet)', 'Apr 2025 (old salary sheet)'], $rows->pluck('for_period')->all(), 'Newest first, the unpaid month by its salary month');
        $unpaid = $rows->first();
        $this->assertNull($unpaid['paid_on']);
        $this->assertSame('unpaid', $unpaid['payment_status']);
        $this->assertSame(9500.0, $unpaid['remaining']);
        $this->assertArrayNotHasKey('sort_date', $unpaid);

        $this->assertCount(0, app(ReportService::class)->rows($this->admin, 'payments', ['from' => '2026-01-01', 'to' => '2026-10-21', 'employee_id' => $e->id]), 'Outside the range: not listed');
    }

    public function test_attendance_api_says_when_the_running_break_started(): void
    {
        $e = $this->employee('BR-1');
        \Laravel\Sanctum\Sanctum::actingAs($e->user, ['*']);
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->postJson('/api/attendance/check-in', ['source' => 'desktop'])->assertSuccessful()->assertJsonPath('data.break_started_at', null);
        Carbon::setTestNow('2026-10-21 13:00:00');
        $this->postJson('/api/attendance/break/start', ['source' => 'desktop'])->assertSuccessful()->assertJsonPath('data.status', 'on_break');
        Carbon::setTestNow('2026-10-21 13:20:00');
        $started = $this->getJson('/api/attendance/today')->assertOk()->json('data.break_started_at');
        $this->assertSame('2026-10-21 13:00', Carbon::parse($started)->setTimezone(config('app.timezone'))->format('Y-m-d H:i'));
        $this->postJson('/api/attendance/break/end', ['source' => 'desktop'])->assertSuccessful()->assertJsonPath('data.break_started_at', null);
    }

    public function test_work_on_an_off_day_is_extra_work_never_late_or_half_day(): void
    {
        $e = $this->employee('EX-1');
        $attendance = app(\App\Services\AttendanceService::class);
        // Sunday 18 Oct: in at 4 PM for 3 hours.
        $sunday = AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-18', 'check_in' => '2026-10-18 16:00:00', 'check_out' => '2026-10-18 19:00:00', 'active_seconds' => 10800, 'status' => 'completed']);
        $sunday->load('employee');
        $this->assertFalse($attendance->isLate($sunday));
        $this->assertSame(0, $attendance->lateMinutes($sunday));
        $this->assertSame(0, $attendance->earlyMinutes($sunday));
        $this->assertFalse($attendance->isHalfDay($sunday));

        $day = $attendance->days($e, Carbon::parse('2026-10-18'), Carbon::parse('2026-10-18'))->first();
        $this->assertSame('extra_work', $day['status']);
        $this->assertTrue($day['extra']);

        // The same late arrival on a working day is still late.
        $monday = AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-19', 'check_in' => '2026-10-19 16:00:00', 'check_out' => '2026-10-19 19:00:00', 'active_seconds' => 10800, 'status' => 'completed']);
        $this->assertTrue($attendance->isLate($monday->load('employee')));

        // No late penalty for the Sunday.
        app(\App\Services\PeopleOperationsService::class)->setPolicy('attendance.late_penalty', 'compliance', 'Late attendance', ['amount_per_minute' => 1, 'grace_minutes' => 0], null, $this->admin->id, '2026-10-01');
        app(\App\Services\PeopleOperationsService::class)->assessCompliance('2026-10-18', '2026-10-19', $this->admin->id);
        $dates = \App\Models\EmployeePenalty::where('employee_id', $e->id)->get()->map(fn ($p) => $p->effective_on->toDateString())->unique()->values()->all();
        $this->assertNotContains('2026-10-18', $dates);
    }

    public function test_removing_a_photo_reloads_the_page(): void
    {
        $e = $this->employee('PH-2', ['photo_path' => 'employee-photos/x.png']);
        Livewire::actingAs($this->admin)->test(EmployeeProfile::class, ['employee' => $e])->call('removePhoto')->assertRedirect(route('people.employees.show', $e));
        $this->assertNull($e->fresh()->photo_path);
    }
}
