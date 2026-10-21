<?php

namespace Tests\Feature;

use App\Livewire\Hr\Dashboard;
use App\Livewire\People\Employees;
use App\Models\AttendanceEmployee;
use App\Models\Announcement;
use App\Models\User;
use App\Services\AttendanceCorrectionService;
use App\Services\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** Admin password reset, correction notifies admins, deleted-announcement cleanup, dashboard metrics. */
class Oct13BatchBTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $employee;
    private User $employeeUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-13 14:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->employeeUser = User::factory()->create(['email' => 'b.emp@example.com', 'password' => Hash::make('OldPassword123')]);
        $this->employeeUser->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'B-EMP', 'name' => 'Bina Employee', 'is_active' => true, 'user_id' => $this->employeeUser->id, 'joined_at' => '2026-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_reset_an_employee_password(): void
    {
        Livewire::actingAs($this->admin)->test(Employees::class)
            ->call('openPassword', $this->employee->id)
            ->assertSet('pwEmployeeId', $this->employee->id)
            ->set('pwPassword', 'BrandNewPass99')
            ->call('resetPassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('BrandNewPass99', $this->employeeUser->fresh()->password));
    }

    public function test_password_reset_enforces_strength(): void
    {
        Livewire::actingAs($this->admin)->test(Employees::class)
            ->call('openPassword', $this->employee->id)
            ->set('pwPassword', 'short')
            ->call('resetPassword')
            ->assertHasErrors('pwPassword');
    }

    public function test_employee_can_no_longer_change_their_own_password(): void
    {
        $this->assertFalse(method_exists(\App\Livewire\Account\Profile::class, 'changePassword'), 'Self-service password change is removed');
    }

    public function test_attendance_correction_notifies_an_admin_with_the_permission(): void
    {
        app(AttendanceCorrectionService::class)->request($this->employee, '2026-10-10', '09:30', '18:30', 'Forgot to check out that day');

        $this->assertTrue(
            $this->admin->notifications()->where('data->kind', 'AttendanceCorrection')->exists(),
            'An admin holding attendance.correct is notified of the correction'
        );
    }

    public function test_attendance_correction_notifies_managers_and_master_admins(): void
    {
        // A Manager-role user (approvals.manage only) who sees it in Pending Approvals.
        $roleManager = User::factory()->create(['email' => 'b.mgr@example.com']);
        $roleManager->assignRole('Manager');
        // The employee's own manager (plain Employee role).
        $leadUser = User::factory()->create(['email' => 'b.lead@example.com']);
        $leadUser->assignRole('Employee');
        $lead = AttendanceEmployee::create(['employee_code' => 'B-LEAD', 'name' => 'Lead', 'is_active' => true, 'user_id' => $leadUser->id, 'joined_at' => '2026-01-01']);
        $this->employee->update(['manager_id' => $lead->id]);
        // A Master Admin, even when the role lost the attendance.correct permission.
        $this->admin->roles->each->revokePermissionTo('attendance.correct');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        app(AttendanceCorrectionService::class)->request($this->employee->fresh(), '2026-10-10', '09:30', '18:30', 'Forgot to check out that day');

        // The bell notification must not wait on a queue worker.
        $this->assertSame('sync', (new \App\Notifications\ApprovalRequested('AttendanceCorrection', 1, 'x'))->viaConnections()['database']);
        foreach ([$roleManager, $leadUser, $this->admin] as $user) {
            $this->assertTrue($user->notifications()->where('data->kind', 'AttendanceCorrection')->exists(), "{$user->email} is notified of the correction");
        }
    }

    public function test_correction_with_break_on_a_day_without_attendance_is_accepted_and_applied(): void
    {
        $service = app(AttendanceCorrectionService::class);
        $correction = $service->request($this->employee, '2026-10-11', '10:00', '19:45', 'Forgot to check in', 45);
        $this->assertSame(45, $correction->requested_break_minutes);

        $service->decide($correction, $this->admin->id, true);
        $session = \App\Models\AttendanceSession::where('employee_id', $this->employee->id)->whereDate('work_date', '2026-10-11')->firstOrFail();
        $this->assertSame(45 * 60, (int) $session->break_seconds);
        $this->assertSame('completed', $session->status);
    }

    public function test_correction_break_without_attendance_or_check_in_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(AttendanceCorrectionService::class)->request($this->employee, '2026-10-11', null, null, 'Break only', 30);
    }

    public function test_correction_break_longer_than_the_day_is_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(AttendanceCorrectionService::class)->request($this->employee, '2026-10-11', '10:00', '11:00', 'Too long', 90);
    }

    public function test_deleting_an_announcement_removes_its_notifications(): void
    {
        $announcement = app(CompanyService::class)->saveAnnouncement(
            ['title' => 'Temp notice', 'body' => 'Body text', 'audience' => 'all', 'is_pinned' => false],
            $this->admin
        );
        $this->assertTrue($this->employeeUser->notifications()->where('data->record_id', $announcement->id)->where('data->kind', 'Announcement')->exists());

        app(CompanyService::class)->deleteAnnouncement($announcement->fresh());

        $this->assertFalse($this->employeeUser->notifications()->where('data->record_id', $announcement->id)->where('data->kind', 'Announcement')->exists(), 'The notification is gone with the announcement');
    }

    public function test_dashboard_exposes_task_completion_and_attendance_counts(): void
    {
        Livewire::actingAs($this->admin)->test(Dashboard::class)
            ->assertViewHas('metrics', fn ($m) => array_key_exists('task_completion_pct', $m)
                && array_key_exists('completed_today', $m)
                && array_key_exists('checked_in', $m)
                && array_key_exists('checked_out', $m));
    }
}
