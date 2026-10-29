<?php

namespace Tests\Feature;

use App\Livewire\Payroll\Payslips;
use App\Models\AttendanceEmployee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\LegacySalaryRecord;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipRequest;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\HrWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Undecided leave: daily reminder, escalation after 24 hours, urgent on the leave day, and "Leave pending" instead of
 * "Absent" (pay unchanged). Payslip requests: this system's payslip at once, older months sent by HR from the old
 * salary sheet or as a file, or refused with a reason.
 */
class PendingLeaveAndPayslipRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $managerUser;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->managerUser = User::factory()->create(['name' => 'Manny Manager']);
        $this->managerUser->assignRole('Employee');
        $manager = AttendanceEmployee::create(['employee_code' => 'MG1', 'name' => 'Manny Manager', 'is_active' => true, 'user_id' => $this->managerUser->id, 'joined_at' => '2026-01-01']);
        $user = User::factory()->create(['name' => 'Priya Verma']);
        $user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'PV1', 'name' => 'Priya Verma', 'is_active' => true, 'user_id' => $user->id, 'manager_id' => $manager->id, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '19:00:00', 'daily_work_hours' => 8]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function kinds(User $user, string $kind): int
    {
        return $user->notifications()->where('data->kind', $kind)->count();
    }

    public function test_undecided_leave_reminder_escalation_urgent_once_each(): void
    {
        $unpaid = LeaveType::where('paid', false)->where('is_other', false)->first() ?? LeaveType::create(['name' => 'Unpaid Leave', 'annual_allowance' => 0, 'paid' => false, 'is_active' => true]);
        $leave = app(HrWorkflowService::class)->requestLeave($this->employee, ['leave_type_id' => $unpaid->id, 'duration' => 'full', 'starts_on' => Carbon::parse('2026-10-21'), 'ends_on' => Carbon::parse('2026-10-21'), 'reason' => 'Family'], false);

        Artisan::call('leave:pending-reminders'); // 09:00: before the daily reminder time, not 24 h old, not its day yet
        $this->assertSame(0, $this->kinds($this->managerUser, 'LeaveReminder'));

        Carbon::setTestNow('2026-10-20 10:15:00');
        Artisan::call('leave:pending-reminders');
        Artisan::call('leave:pending-reminders');
        $this->assertSame(1, $this->kinds($this->managerUser, 'LeaveReminder'), 'Once a day');
        $this->assertStringContainsString("Reminder: Priya Verma's Unpaid Leave", $this->managerUser->notifications()->where('data->kind', 'LeaveReminder')->first()->data['message']);

        Carbon::setTestNow('2026-10-21 10:30:00'); // next day: a new reminder, 24 h passed, and the leave day has come
        Artisan::call('leave:pending-reminders');
        Artisan::call('leave:pending-reminders');
        $this->assertSame(2, $this->kinds($this->managerUser, 'LeaveReminder'));
        $this->assertSame(1, $this->kinds($this->admin, 'LeaveEscalated'));
        $this->assertSame(1, $this->kinds($this->admin, 'LeaveUrgent'));

        // The day shows "Leave pending" (still absent underneath: not paid until approved).
        Carbon::setTestNow('2026-10-22 10:00:00');
        $day = app(AttendanceService::class)->days($this->employee, Carbon::parse('2026-10-21'), Carbon::parse('2026-10-21'))->first();
        $this->assertSame(['absent', true], [$day['status'], $day['leave_pending']]);
        $this->assertStringContainsString('Leave pending', $day['label']);
        \Laravel\Sanctum\Sanctum::actingAs($this->employee->user, ['*']);
        $this->assertTrue($this->getJson('/api/attendance/history?from=2026-10-21&to=2026-10-21')->assertOk()->json('data.0.leave_pending'));

        // Decided: no more reminders.
        app(HrWorkflowService::class)->decideLeave($leave, $this->admin->id, false, 'No');
        Carbon::setTestNow('2026-10-23 11:00:00');
        Artisan::call('leave:pending-reminders');
        $this->assertSame(2, $this->kinds($this->managerUser, 'LeaveReminder'));
    }

    public function test_old_month_payslip_requested_and_sent_from_legacy_salary_or_file(): void
    {
        Storage::fake('local');
        LegacySalaryRecord::create(['employee_id' => $this->employee->id, 'employee_code' => 'PV1', 'payroll_month' => '2026-08', 'name' => 'Priya Verma', 'name_key' => 'priya verma', 'basic_salary' => 20000, 'total_working_days' => 25, 'present_days' => 24, 'late_coming_deduction' => 150, 'total_payment_deduction' => 150, 'salary_after_deduction' => 19050, 'salary_paid' => 19050, 'payment_date' => '2026-09-05']);

        // Website: the employee asks for August (before this system).
        Livewire::actingAs($this->employee->user)->test(Payslips::class)->assertSee('Request a payslip')
            ->set('reqMonth', '2026-08')->set('reqNote', 'Bank loan')->call('requestPayslip')->assertHasNoErrors();
        $request = PayslipRequest::firstOrFail();
        $this->assertSame('pending', $request->status);
        $this->assertStringContainsString('asked for the August 2026 payslip (Bank loan). That month is in Legacy Salary', $this->admin->notifications()->where('data->kind', 'PayslipRequest')->first()->data['message']);
        Livewire::actingAs($this->employee->user)->test(Payslips::class)->set('reqMonth', '2026-08')->call('requestPayslip')->assertHasErrors('reqMonth');

        // HR sends it from Legacy Salary in one click; the employee opens it.
        Livewire::actingAs($this->admin)->test(Payslips::class)->assertSee('Send from Legacy Salary')->call('sendLegacy', $request->id);
        $this->assertSame('sent', $request->fresh()->status);
        $this->actingAs($this->employee->user)->get(route('payslip-requests.show', $request))->assertOk()->assertSee('August 2026')->assertSee('19,050.00')->assertSee('Late coming');
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('payslip-requests.show', $request))->assertForbidden();

        // App: a month not in Legacy Salary -> HR uploads the file; the app opens it through a signed link.
        \Laravel\Sanctum\Sanctum::actingAs($this->employee->user, ['*']);
        $this->postJson('/api/payslip-requests', ['month' => '2026-07'])->assertCreated()->assertJsonPath('data.status', 'pending');
        $july = PayslipRequest::where('month', '2026-07')->firstOrFail();
        Livewire::actingAs($this->admin)->test(Payslips::class)->call('openUpload', $july->id)->set('uploadFile', UploadedFile::fake()->create('july.pdf', 20, 'application/pdf'))->call('saveUpload')->assertHasNoErrors();
        \Laravel\Sanctum\Sanctum::actingAs($this->employee->user, ['*']);
        $url = $this->getJson('/api/payslip-requests')->assertOk()->json('data.0.url');
        $this->assertNotNull($url);
        $this->get($url)->assertOk();

        // Refusing needs a reason; the current month is not ready yet.
        $june = app(\App\Services\PayslipRequestService::class)->request($this->employee, '2026-06', null, $this->employee->user);
        Livewire::actingAs($this->admin)->test(Payslips::class)->call('openReject', $june->id)->call('saveReject')->assertHasErrors('rejectNote')->set('rejectNote', 'No record for June')->call('saveReject');
        $this->assertSame(['rejected', 'No record for June'], [$june->fresh()->status, $june->fresh()->decision_note]);
        \Laravel\Sanctum\Sanctum::actingAs($this->employee->user, ['*']);
        $this->postJson('/api/payslip-requests', ['month' => '2026-10'])->assertStatus(422);
    }

    public function test_a_payslip_this_system_made_is_given_at_once(): void
    {
        $run = PayrollRun::create(['period_starts_on' => '2026-09-01', 'period_ends_on' => '2026-09-30', 'status' => 'locked', 'created_by' => $this->admin->id]);
        $item = PayrollItem::create(['payroll_run_id' => $run->id, 'employee_id' => $this->employee->id, 'gross_amount' => 20000, 'net_amount' => 20000, 'calculation_data' => []]);
        Payslip::create(['payroll_item_id' => $item->id, 'employee_id' => $this->employee->id, 'issued_at' => now(), 'reference' => 'PS-TEST-1']);

        \Laravel\Sanctum\Sanctum::actingAs($this->employee->user, ['*']);
        $made = $this->postJson('/api/payslip-requests', ['month' => '2026-09'])->assertCreated()->json('data');
        $this->assertSame('sent', $made['status']);
        $this->assertNotNull($made['url']);
        $this->assertSame(0, $this->admin->notifications()->where('data->kind', 'PayslipRequest')->count(), 'HR is not bothered');
    }
}
