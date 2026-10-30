<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\HrWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Leave nobody has acted on: listed at the top of the administrators' dashboard until decided, and on the Super Admin
 * Telegram chat every morning (one list) and once a request has waited 24 hours.
 */
class UndecidedLeaveAlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-28 09:00:00');
        config(['services.telegram' => ['enabled' => true, 'bot_token' => '123456:TEST', 'bot_username' => 'sws_hrms_bot', 'admin_chat_id' => '1001', 'webhook_secret' => 'x', 'queue_connection' => 'sync', 'link_minutes' => 10]]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create(['name' => 'Priya Verma']);
        $user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'EMP002', 'name' => 'Priya Verma', 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function adminTelegram(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_contains($r->url(), '/sendMessage') && (string) ($r['chat_id'] ?? '') === '1001')->map(fn (Request $r) => $r['text'])->values()->all();
    }

    private function leave(string $from, string $to): LeaveRequest
    {
        $type = LeaveType::where('name', 'Annual Leave')->firstOrFail();

        return app(HrWorkflowService::class)->requestLeave($this->employee, ['leave_type_id' => $type->id, 'duration' => 'full', 'starts_on' => Carbon::parse($from), 'ends_on' => Carbon::parse($to), 'reason' => 'Family function']);
    }

    public function test_undecided_leave_is_listed_on_the_admin_dashboard_until_decided(): void
    {
        $leave = $this->leave('2026-11-02', '2026-11-03');
        Carbon::setTestNow('2026-10-29 11:00:00'); // waited 26 hours

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSee('Leave requests not decided yet (1)')->assertSee('Priya Verma')->assertSee('02 Nov – 03 Nov')->assertSee('1 day')->assertSee('waiting more than 24 hours');
        $this->actingAs($this->employee->user)->get(route('dashboard'))->assertOk()->assertDontSee('Leave requests not decided yet');

        app(HrWorkflowService::class)->decideLeave($leave, $this->admin->id, true);
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertDontSee('Leave requests not decided yet');
    }

    public function test_telegram_morning_list_and_24_hour_message_once_each(): void
    {
        $this->leave('2026-11-02', '2026-11-02');
        $before = count($this->adminTelegram()); // the "new leave request" message

        Carbon::setTestNow('2026-10-29 10:05:00');
        $this->artisan('leave:pending-reminders')->assertSuccessful();
        $this->artisan('leave:pending-reminders')->assertSuccessful();
        $sent = array_slice($this->adminTelegram(), $before);
        $this->assertCount(2, $sent, 'One morning list and one 24-hour message, however often it runs');
        $text = implode("\n", $sent);
        $this->assertStringContainsString('LEAVE REQUESTS NOT DECIDED (1)', $text);
        $this->assertStringContainsString('1. Priya Verma · Annual Leave · 02 Nov · waiting 1 day', $text);
        $this->assertStringContainsString('LEAVE NOT DECIDED FOR 24 HOURS', $text);
        $this->assertStringContainsString('Pending - no action taken yet', $text);

        // Next morning: the list again (it is still undecided), the 24-hour message is not repeated.
        Carbon::setTestNow('2026-10-30 10:05:00');
        $this->artisan('leave:pending-reminders')->assertSuccessful();
        $this->assertCount(3, array_slice($this->adminTelegram(), $before));
    }
}
