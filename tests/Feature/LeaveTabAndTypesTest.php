<?php

namespace Tests\Feature;

use App\Livewire\People\EmployeeProfile;
use App\Livewire\System\Settings;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\HrWorkflowService;
use App\Services\PeopleOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Leave types can be deleted when unused (else made inactive); the profile's Leave tab shows every leave and absent days. */
class LeaveTabAndTypesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-26 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create(['email' => 'leave-tab@example.com']);
        $user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'LT-1', 'name' => 'Lata Leave', 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-10-19']);
        app(PeopleOperationsService::class)->setPolicy('calendar.working_days', 'calendar', 'Working calendar', ['days' => [1, 2, 3, 4, 5], 'holidays' => []], null, $this->admin->id, '2026-01-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_unused_leave_type_is_deleted_and_a_used_one_made_inactive(): void
    {
        $mistake = LeaveType::create(['name' => 'Typo Leave', 'annual_allowance' => 0, 'paid' => false, 'is_active' => true]);
        $used = LeaveType::create(['name' => 'Casual Leave', 'annual_allowance' => 12, 'paid' => true, 'is_active' => true]);
        LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $used->id, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-20', 'duration' => 'full', 'days' => 1, 'reason' => 'x', 'status' => 'approved']);

        Livewire::actingAs($this->admin)->test(Settings::class)->assertSee('Make inactive')->call('deleteType', $mistake->id)->call('deleteType', $used->id);
        $this->assertNull(LeaveType::find($mistake->id), 'Never used: deleted');
        $this->assertFalse((bool) $used->fresh()->is_active, 'Used: kept, made inactive');
        $this->assertSame(1, LeaveRequest::where('leave_type_id', $used->id)->count(), 'Leave history untouched');
        $this->assertDatabaseHas('audit_logs', ['action' => 'leave_type.deleted']);
    }

    public function test_leave_tab_lists_leave_with_full_or_half_day_and_absent_days(): void
    {
        $casual = LeaveType::create(['name' => 'Casual Leave', 'annual_allowance' => 12, 'paid' => true, 'is_active' => true]);
        LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $casual->id, 'starts_on' => '2026-10-21', 'ends_on' => '2026-10-21', 'duration' => 'half', 'days' => 0.5, 'reason' => 'Doctor visit', 'status' => 'approved', 'decided_by' => $this->admin->id, 'decided_at' => now()]);
        // 19, 20 Oct worked; 21 Oct half-day leave + worked; 22, 23 Oct absent with no leave.
        foreach (['2026-10-19', '2026-10-20', '2026-10-21'] as $d) AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => $d, 'check_in' => "$d 10:00:00", 'check_out' => "$d 18:30:00", 'status' => 'completed']);

        $page = Livewire::actingAs($this->admin)->test(EmployeeProfile::class, ['employee' => $this->employee])
            ->assertSee('Leave taken')->assertSee('Half day')->assertSee('Doctor visit')->assertSee('Approved by SWS Admin')
            ->assertSee('Absent without leave · 2 days')->assertSee('Thu, 22 Oct 2026')->assertSee('Record as leave');
        $history = $page->viewData('leaveHistory');
        $this->assertSame(0.5, (float) $history['summary']['total']);
        $this->assertSame(1, $history['summary']['half']);
        $this->assertSame(['2026-10-23', '2026-10-22'], $history['absent']->all());

        // HR records 22 Oct as paid leave: approved at once, the day is on leave, no "needs review" message.
        $page->call('openRecordLeave', '2026-10-22')->set('recordTypeId', $casual->id)->set('recordDuration', 'full')->set('recordNote', 'Informed HR on phone: fever')->call('recordLeave')->assertHasNoErrors();
        $leave = LeaveRequest::where('employee_id', $this->employee->id)->whereDate('starts_on', '2026-10-22')->firstOrFail();
        $this->assertSame('approved', $leave->status);
        $this->assertSame('on_leave', app(AttendanceService::class)->days($this->employee, Carbon::parse('2026-10-22'), Carbon::parse('2026-10-22'))->first()['status']);
        $this->assertFalse(\Illuminate\Notifications\DatabaseNotification::where('data->kind', 'LeaveRequest')->where('data->record_id', $leave->id)->where('data->message', 'like', '%needs review%')->exists());
        $this->assertSame(['2026-10-23'], $page->viewData('leaveHistory')['absent']->all());
    }

    public function test_only_hr_can_record_leave(): void
    {
        $other = User::factory()->create(['email' => 'peer@example.com']);
        $other->assignRole('Employee');
        AttendanceEmployee::create(['employee_code' => 'LT-2', 'name' => 'Peer', 'is_active' => true, 'user_id' => $other->id, 'joined_at' => '2026-01-01']);
        Livewire::actingAs($this->employee->user)->test(EmployeeProfile::class, ['employee' => $this->employee])
            ->assertDontSee('Record as leave')->call('openRecordLeave', '2026-10-22')->assertForbidden();
    }
}
