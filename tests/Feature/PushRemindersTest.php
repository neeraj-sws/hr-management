<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PushToken;
use App\Models\User;
use App\Services\AttendanceReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Phone reminders: before the shift, forgot to check out, leave decisions and payslip ready. */
class PushRemindersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function employee(string $code): AttendanceEmployee
    {
        $user = User::factory()->create(['email' => strtolower($code).'@example.com']);
        $user->assignRole('Employee');

        return AttendanceEmployee::create(['employee_code' => $code, 'name' => "Person {$code}", 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '19:00:00', 'daily_work_hours' => 8.5]);
    }

    private function kinds(AttendanceEmployee $e, string $kind): int
    {
        return $e->user->notifications()->where('data->kind', $kind)->count();
    }

    public function test_reminder_before_the_shift_goes_once_and_only_to_people_due_in(): void
    {
        $due = $this->employee('RM-1');
        $in = $this->employee('RM-2');
        $leave = $this->employee('RM-3');
        AttendanceSession::create(['employee_id' => $in->id, 'work_date' => '2026-10-22', 'check_in' => '2026-10-22 09:40:00', 'status' => 'working']);
        LeaveRequest::create(['employee_id' => $leave->id, 'leave_type_id' => LeaveType::firstOrFail()->id, 'starts_on' => '2026-10-22', 'ends_on' => '2026-10-22', 'days' => 1, 'duration' => 'full', 'reason' => 'Off', 'status' => 'approved']);
        $reminders = app(AttendanceReminderService::class);

        Carbon::setTestNow('2026-10-22 09:30:00');
        $reminders->beforeShift();
        $this->assertSame(0, $this->kinds($due, 'ShiftReminder'), 'Too early (30 min before)');

        Carbon::setTestNow('2026-10-22 09:47:00');
        $reminders->beforeShift();
        $reminders->beforeShift();
        $this->assertSame(1, $this->kinds($due, 'ShiftReminder'), 'Once, 13 min before');
        $this->assertStringContainsString('Your shift starts at 10:00 AM', $due->user->notifications()->first()->data['message']);
        $this->assertSame(0, $this->kinds($in, 'ShiftReminder'), 'Already checked in');
        $this->assertSame(0, $this->kinds($leave, 'ShiftReminder'), 'On leave');

        Carbon::setTestNow('2026-10-25 09:50:00'); // Sunday
        $reminders->beforeShift();
        $this->assertSame(1, $this->kinds($due, 'ShiftReminder'), 'No reminder on a weekly off');
    }

    public function test_reminder_when_still_checked_in_after_the_shift(): void
    {
        $e = $this->employee('CO-1');
        AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-22', 'check_in' => '2026-10-22 10:04:00', 'status' => 'working']);
        $reminders = app(AttendanceReminderService::class);

        Carbon::setTestNow('2026-10-22 19:20:00');
        $reminders->forgotCheckout();
        $this->assertSame(0, $this->kinds($e, 'CheckoutReminder'), 'Only 20 min after the end');

        Carbon::setTestNow('2026-10-22 19:35:00');
        $reminders->forgotCheckout();
        $reminders->forgotCheckout();
        $this->assertSame(1, $this->kinds($e, 'CheckoutReminder'));
        $this->assertStringContainsString('still checked in (since 10:04 AM)', $e->user->notifications()->first()->data['message']);
    }

    public function test_first_salary_payment_says_the_payslip_is_ready(): void
    {
        $e = $this->employee('PS-1');
        \App\Models\SalaryProfile::create(['employee_id' => $e->id, 'monthly_salary' => 10000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 25, 'standard_daily_hours' => 8.5]);
        $run = \App\Models\PayrollRun::create(['period_starts_on' => '2026-09-01', 'period_ends_on' => '2026-09-30', 'status' => 'locked', 'created_by' => $this->admin->id]);
        $item = \App\Models\PayrollItem::create(['payroll_run_id' => $run->id, 'employee_id' => $e->id, 'gross_amount' => 10000, 'net_amount' => 10000, 'adjustments' => 0, 'calculation_data' => []]);
        $workflow = app(\App\Services\HrWorkflowService::class);

        $workflow->pay($item, ['amount' => 6000, 'paid_on' => '2026-10-05', 'method' => 'bank'], $this->admin->id);
        $this->assertSame(1, $this->kinds($e, 'Payslip'));
        $this->assertStringContainsString('Your payslip for September 2026 is ready', $e->user->notifications()->where('data->kind', 'Payslip')->first()->data['message']);

        $workflow->pay($item->fresh(), ['amount' => 4000, 'paid_on' => '2026-10-10', 'method' => 'bank'], $this->admin->id);
        $this->assertSame(1, $this->kinds($e, 'Payslip'), 'Said once');
        $this->assertSame(1, $this->kinds($e, 'SalaryPayment'), 'Later payments are reported as further payments');
    }

    public function test_leave_decision_reaches_the_phone_at_once(): void
    {
        config(['services.expo_push.enabled' => true, 'queue.default' => 'database']); // a real queue that nobody works off
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);
        $e = $this->employee('LV-9');
        PushToken::create(['user_id' => $e->user_id, 'token' => 'ExponentPushToken[test-phone]', 'platform' => 'android']);
        Carbon::setTestNow('2026-10-22 11:00:00');
        $leave = app(\App\Services\HrWorkflowService::class)->requestLeave($e, ['leave_type_id' => LeaveType::firstOrFail()->id, 'duration' => 'full', 'starts_on' => Carbon::parse('2026-10-26'), 'ends_on' => Carbon::parse('2026-10-26'), 'reason' => 'Family']);
        $approval = \App\Models\ApprovalRequest::where('approvable_type', LeaveRequest::class)->where('approvable_id', $leave->id)->firstOrFail();
        app(\App\Services\ApprovalInbox::class)->decide($this->admin, $approval, true);

        // No queue worker needed: the push was sent during the request.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'exp.host') && str_contains(json_encode($r->data()), 'approved your leave'));
    }
}
