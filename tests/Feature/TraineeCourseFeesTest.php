<?php

namespace Tests\Feature;

use App\Livewire\Training\Fees;
use App\Livewire\Training\MyFees;
use App\Models\AttendanceEmployee;
use App\Models\TrainingEnrollment;
use App\Models\User;
use App\Services\TrainingFeeService;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A trainee sees their course and fee (web page, dashboard card, app): what is paid, what is due, each installment
 * with its due date and status; they are told when enrolled and when a payment is received, and reminded of each
 * installment (3 days before, on the day, once overdue - HR too).
 */
class TraineeCourseFeesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $user;
    private AttendanceEmployee $trainee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-01 10:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->user = User::factory()->create(['email' => 'tahir@example.com']);
        $this->user->assignRole('Employee');
        $this->trainee = AttendanceEmployee::create(['employee_code' => 'TR-9', 'name' => 'Tahir Trainee', 'email' => $this->user->email, 'is_active' => true, 'user_id' => $this->user->id, 'joined_at' => '2026-01-01', 'employment_type' => 'trainee']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function messages(User $user): array
    {
        return $user->notifications()->get()->map(fn ($n) => (string) ($n->data['message'] ?? ''))->all();
    }

    private function enroll(): TrainingEnrollment
    {
        Livewire::actingAs($this->admin)->test(Fees::class)->call('enroll')
            ->set('employeeId', $this->trainee->id)->set('courseName', 'Web designing')->set('totalFee', '20000')->set('discount', '3000')
            ->set('installments', '3')->set('startsOn', '2026-10-05')->call('saveEnrollment')->assertHasNoErrors();

        return TrainingEnrollment::firstOrFail();
    }

    public function test_installments_are_planned_and_hr_can_change_them(): void
    {
        $e = $this->enroll();
        // ₹17,000 in 3 equal monthly installments from 05 Oct.
        $this->assertSame([[5666.67, '2026-10-05'], [5666.67, '2026-11-05'], [5666.66, '2026-12-05']], array_map(fn ($r) => [$r['amount'], $r['due_on']], $e->plan()));
        $this->assertStringContainsString('You are enrolled in Web designing: fee ₹17,000.00 (after a discount of ₹3,000.00), 3 installments; first ₹5,666.67 due on 05 Oct 2026.', implode("\n", $this->messages($this->user)));

        // HR moves the second one and makes them 7,000 + 5,000 + 5,000.
        Livewire::actingAs($this->admin)->test(Fees::class)->call('enroll', $e->id)
            ->set('planRows.0.amount', '7000')->set('planRows.1.amount', '5000')->set('planRows.2.amount', '5000')->set('planRows.1.due_on', '2026-11-10')
            ->call('saveEnrollment')->assertHasNoErrors();
        $this->assertSame([[7000.0, '2026-10-05'], [5000.0, '2026-11-10'], [5000.0, '2026-12-05']], array_map(fn ($r) => [(float) $r['amount'], $r['due_on']], $e->fresh()->plan()));
        // Amounts that do not add up to the fee are refused.
        Livewire::actingAs($this->admin)->test(Fees::class)->call('enroll', $e->id)->set('planRows.0.amount', '1000')->call('saveEnrollment')->assertHasErrors('planRows');
    }

    public function test_payment_notice_status_page_menu_dashboard_and_app(): void
    {
        $e = $this->enroll();
        app(TrainingFeeService::class)->recordPayment($e, ['amount' => 5666.67, 'paid_on' => '2026-10-05', 'method' => 'upi', 'reference' => 'UPI-1'], $this->admin);
        $this->assertStringContainsString('Received ₹5,666.67 for Web designing (receipt FEE-2026-0001). Paid ₹5,666.67 of ₹17,000.00; still due ₹11,333.33, next ₹5,666.67 due on 05 Nov 2026.', implode("\n", $this->messages($this->user)));

        Carbon::setTestNow('2026-11-08 10:00:00'); // the 2nd installment is 3 days late
        $rows = $e->fresh('payments')->installmentRows();
        $this->assertSame(['paid', 'overdue', 'upcoming'], array_column($rows, 'status'));

        $this->assertContains('training.my-fees', collect(Navigation::for($this->user))->flatMap(fn ($g) => collect($g['items'])->pluck('route'))->all());
        Livewire::actingAs($this->user)->test(MyFees::class)
            ->assertSeeText('Web designing')->assertSeeText('₹17,000.00')->assertSeeText('₹11,333.33')
            ->assertSeeText('Next installment #2: ₹5,666.67')->assertSeeText('overdue by 3 days')->assertSeeText('Overdue')->assertSeeText('FEE-2026-0001');
        $this->actingAs($this->user)->get(route('dashboard'))->assertOk()->assertSeeText('My course: Web designing')->assertSeeText('still due ₹11,333.33');

        Sanctum::actingAs($this->user, ['*']);
        $this->getJson('/api/my/course-fees')->assertOk()
            ->assertJsonPath('data.0.next_due.no', 2)->assertJsonPath('data.0.schedule.1.status', 'overdue')->assertJsonPath('data.0.schedule.0.status_label', 'Paid');
    }

    public function test_reminders_go_once_three_days_before_on_the_day_and_when_overdue(): void
    {
        $e = $this->enroll();
        app(TrainingFeeService::class)->recordPayment($e, ['amount' => 5666.67, 'paid_on' => '2026-10-05', 'method' => 'cash'], $this->admin);
        $fees = app(TrainingFeeService::class);
        $count = fn (string $text) => count(array_filter($this->messages($this->user), fn ($m) => str_contains($m, $text)));

        Carbon::setTestNow('2026-11-02 09:30:00');
        $this->assertSame(1, $fees->sendReminders());
        $this->assertSame(0, $fees->sendReminders(), 'Once only');
        $this->assertSame(1, $count('installment #2 of ₹5,666.67 for Web designing is due on 05 Nov 2026 (in 3 days)'));

        Carbon::setTestNow('2026-11-05 09:30:00');
        $fees->sendReminders();
        $this->assertSame(1, $count('is due today (05 Nov 2026)'));

        Carbon::setTestNow('2026-11-06 09:30:00');
        $fees->sendReminders();
        $fees->sendReminders();
        $this->assertSame(1, $count('was due on 05 Nov 2026 and is not paid yet'), 'Overdue: once');
        $this->assertTrue(collect($this->messages($this->admin))->contains(fn ($m) => str_contains($m, 'Tahir Trainee: installment #2') && str_contains($m, 'not paid yet')), 'HR is told too');
        $this->artisan('training:fee-reminders')->assertSuccessful();
    }
}
