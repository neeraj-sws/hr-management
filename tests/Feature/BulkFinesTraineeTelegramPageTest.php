<?php

namespace Tests\Feature;

use App\Livewire\Compliance\Penalties;
use App\Models\AttendanceEmployee;
use App\Models\EmployeePenalty;
use App\Models\SalaryAdjustment;
use App\Models\SalaryProfile;
use App\Models\TelegramMessage;
use App\Models\TrainingEnrollment;
use App\Models\User;
use App\Services\HrWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * HR takes back many fines at once with one reason (history kept; a paid salary gets it back next time); a trainee's
 * app shows the course fees instead of salary; the Telegram page has select all and tidy latest messages.
 */
class BulkFinesTraineeTelegramPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create(['name' => 'Abheeshri Parmar']);
        $user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'EMP-11', 'name' => 'Abheeshri Parmar', 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '19:00:00', 'daily_work_hours' => 8]);
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 20000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 25, 'standard_daily_hours' => 8]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fine(string $date): EmployeePenalty
    {
        return EmployeePenalty::create(['employee_id' => $this->employee->id, 'effective_on' => $date, 'amount' => 500, 'reason' => 'Late: came 10:30 AM', 'requested_by' => $this->admin->id, 'status' => 'approved', 'source_key' => 'late:'.$this->employee->id.':'.$date]);
    }

    public function test_take_back_a_month_of_fines_with_one_reason(): void
    {
        $october = collect(['2026-10-05', '2026-10-06', '2026-10-07'])->map(fn ($d) => $this->fine($d));
        $november = $this->fine('2026-11-03');

        Livewire::actingAs($this->admin)->test(Penalties::class)
            ->set('employee', $this->employee->id)->set('month', '2026-10')->call('selectAllMatching')
            ->assertSet('selected', $october->pluck('id')->map(fn ($id) => (string) $id)->values()->all())->assertSee('1,500.00')
            ->call('openBulk')->call('saveBulk')->assertHasErrors('bulkNote')
            ->set('bulkNote', 'Warning given on 29 Oct; October late fines taken back')->call('saveBulk')->assertHasNoErrors();

        foreach ($october as $f) {
            $this->assertSame('waived', $f->fresh()->status);
            $this->assertSame('Taken back: Warning given on 29 Oct; October late fines taken back', $f->fresh()->decision_note);
        }
        $this->assertSame('approved', $november->fresh()->status, 'November is not touched');
        $this->assertSame(1, $this->employee->user->notifications()->where('data->kind', 'EmployeePenalty')->count(), 'One notification for all');
        $this->assertStringContainsString('3 fine(s) of yours, Rs 1,500.00 in all (05 Oct, 06 Oct, 07 Oct), were taken back.', $this->employee->user->notifications()->first()->data['message']);
        Livewire::actingAs($this->employee->user)->test(Penalties::class)->assertSee('Why removed')->assertSee('Warning given on 29 Oct');
    }

    public function test_a_fine_in_a_paid_salary_is_paid_back_in_the_next_salary(): void
    {
        $fine = $this->fine('2026-10-05');
        $workflow = app(HrWorkflowService::class);
        $workflow->approvePayroll($workflow->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id), $this->admin->id);

        $done = app(\App\Services\PeopleOperationsService::class)->removeFines([$fine->id], 'Taken back after the warning', $this->admin->id);
        $this->assertSame(['removed' => 0, 'paid_back' => 1, 'skipped' => 0], $done);
        $this->assertSame('approved', $fine->fresh()->status);
        $this->assertTrue($fine->fresh()->isPaidBack());
        $this->assertSame(500.0, (float) SalaryAdjustment::where('employee_id', $this->employee->id)->where('kind', 'arrear')->value('amount'));
        // Done once: not paid back twice.
        $this->assertSame(['removed' => 0, 'paid_back' => 0, 'skipped' => 1], app(\App\Services\PeopleOperationsService::class)->removeFines([$fine->id], 'again please', $this->admin->id));
    }

    public function test_trainee_app_shows_course_fees_not_salary(): void
    {
        $this->employee->update(['employment_type' => 'trainee']);
        TrainingEnrollment::create(['employee_id' => $this->employee->id, 'course_name' => 'Laravel Course', 'total_fee' => 30000, 'discount' => 5000, 'installments' => 3, 'starts_on' => '2026-09-01', 'status' => 'active', 'created_by' => $this->admin->id]);

        \Laravel\Sanctum\Sanctum::actingAs($this->employee->user, ['*']);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.is_trainee', true);
        $course = $this->getJson('/api/my/course-fees')->assertOk()->json('data.0');
        $this->assertSame(['Laravel Course', 25000.0, 0.0, 25000.0, 'Studying'], [$course['course'], (float) $course['payable'], (float) $course['paid'], (float) $course['due'], $course['status_label']]);
    }

    public function test_telegram_page_select_all_and_message_filters(): void
    {
        TelegramMessage::create(['key' => 'a1', 'target' => 'admin', 'event' => 'check_in', 'status' => 'sent']);
        TelegramMessage::create(['key' => 'u1', 'target' => 'user', 'user_id' => $this->employee->user_id, 'status' => 'failed', 'error' => '403: blocked']);

        Livewire::actingAs($this->admin)->test(\App\Livewire\System\Telegram::class)
            ->call('setAll', 'admin', false)->assertSet('form.admin.check_in', false)->assertSet('form.admin.payslip', false)
            ->call('setAll', 'employee', false)->assertSet('form.employee.check_out', false)
            ->call('setAll', 'admin', true)->assertSet('form.admin.fine', true)
            ->assertSee('Today')->assertSee('Attendance started (check-in, late shown)')->assertSee('Abheeshri Parmar')
            ->set('msgStatus', 'failed')->assertSee('403: blocked')->assertViewHas('messages', fn ($m) => $m->total() === 1)
            ->set('msgStatus', '')->set('msgTarget', 'admin')->assertViewHas('messages', fn ($m) => $m->total() === 1 && $m->first()->key === 'a1');
    }
}
