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

    public function test_removing_a_photo_reloads_the_page(): void
    {
        $e = $this->employee('PH-2', ['photo_path' => 'employee-photos/x.png']);
        Livewire::actingAs($this->admin)->test(EmployeeProfile::class, ['employee' => $e])->call('removePhoto')->assertRedirect(route('people.employees.show', $e));
        $this->assertNull($e->fresh()->photo_path);
    }
}
