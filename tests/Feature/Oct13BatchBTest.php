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
