<?php

namespace Tests\Feature;

use App\Livewire\System\Telegram;
use App\Models\AttendanceEmployee;
use App\Models\EmployeePenalty;
use App\Models\LeaveType;
use App\Models\TelegramLink;
use App\Models\TelegramSetting;
use App\Models\User;
use App\Services\HrWorkflowService;
use App\Services\PeopleOperationsService;
use App\Services\TelegramNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A personal reason ("personal work") rejects a leave request / a late fine's reason automatically, with the reason
 * sent to the employee. The Telegram bot is set up in System > Telegram (token encrypted, never shown) instead of .env.
 */
class PersonalReasonAndTelegramSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsawQ';

    private User $admin;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-28 09:32:00');
        config(['services.telegram' => ['enabled' => true, 'bot_token' => null, 'bot_username' => null, 'admin_chat_id' => null, 'webhook_secret' => 'hook-secret', 'queue_connection' => 'sync', 'link_minutes' => 10]]);
        Http::fake(['api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'sws_hrms_bot']]), 'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create(['name' => 'Rahul Sharma']);
        $user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'EMP001', 'name' => 'Rahul Sharma', 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '19:00:00', 'daily_work_hours' => 8]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function bot(): void
    {
        app(TelegramNotificationService::class)->saveBot(true, self::TOKEN, '1001', $this->admin->id);
    }

    private function sentTo(string $chatId): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => str_contains($r->url(), '/sendMessage') && (string) ($r['chat_id'] ?? '') === $chatId)->map(fn (Request $r) => $r['text'])->values()->all();
    }

    private function leave(string $reason, bool $notify = true)
    {
        $annual = LeaveType::where('name', 'Annual Leave')->firstOrFail();

        return app(HrWorkflowService::class)->requestLeave($this->employee, ['leave_type_id' => $annual->id, 'duration' => 'full', 'starts_on' => Carbon::parse('2026-10-29'), 'ends_on' => Carbon::parse('2026-10-29'), 'reason' => $reason], $notify);
    }

    public function test_leave_with_a_personal_reason_is_rejected_and_the_employee_is_told_why(): void
    {
        $this->bot();
        $leave = $this->leave('Personal work at home');
        $this->assertSame('rejected', $leave->status);
        $this->assertStringStartsWith('Rejected automatically: a personal reason', $leave->decision_note);
        $note = $this->employee->user->notifications()->first();
        $this->assertStringContainsString('Your leave request (29 Oct 2026) was rejected automatically.', $note->data['message']);
        $this->assertSame(0, $this->admin->notifications()->count(), 'Nobody is asked to review it');
        $admin = $this->sentTo('1001');
        $this->assertCount(1, $admin);
        $this->assertStringContainsString('LEAVE REJECTED AUTOMATICALLY', $admin[0]);
        $this->assertStringContainsString('Reason given: Personal work at home', $admin[0]);

        // A valid reason goes for approval as usual; the rejected one does not block it.
        $this->assertSame('pending', $this->leave('Fever, doctor advised rest')->status);
    }

    public function test_hr_recorded_leave_and_switched_off_rule_are_not_rejected(): void
    {
        $this->assertSame('pending', $this->leave('personal', false)->status, 'HR records it - never rejected');
        DB::table('leave_requests')->delete();

        Livewire::actingAs($this->admin)->test(\App\Livewire\System\Settings::class)
            ->assertSet('personalLeave', true)->assertSet('personalPhrases', 'personal, private work, own work, niji, nijee')
            ->set('personalLeave', false)->set('personalPhrases', 'Personal, ghar ka kaam, personal')->call('savePersonalReasons')->assertHasNoErrors()
            ->assertSet('personalPhrases', 'personal, ghar ka kaam');
        $this->assertSame('pending', $this->leave('Personal reason')->status, 'Switched off for leave');
    }

    public function test_whole_words_only(): void
    {
        $this->assertNull(\App\Support\PersonalReason::match('I personally need to visit the hospital', 'leave'));
        $this->assertSame('personal', \App\Support\PersonalReason::match('PERSONAL kaam', 'leave'));
        $this->assertSame('personal', \App\Support\PersonalReason::match('Due to some personal reason.', 'late'));
    }

    public function test_a_personal_late_reason_is_rejected_the_fine_stays_and_a_valid_reason_can_follow(): void
    {
        $this->bot();
        $fine = EmployeePenalty::create(['employee_id' => $this->employee->id, 'effective_on' => '2026-10-27', 'amount' => 500, 'reason' => 'Late: came 10:30 AM', 'requested_by' => $this->admin->id, 'status' => 'approved', 'source_key' => 'late:'.$this->employee->id.':2026-10-27']);
        $people = app(PeopleOperationsService::class);

        $appeal = $people->appealPenalty($fine->load('employee'), $this->employee->user, 'Personal work in the morning');
        $this->assertTrue($appeal->isAutoRejected());
        $this->assertSame('approved', $fine->fresh()->status, 'The fine stays');
        $this->assertStringContainsString('the fine of 500.00 stays. Rejected automatically', $this->employee->user->notifications()->first()->data['message']);
        $this->assertStringContainsString('LATE REASON REJECTED AUTOMATICALLY', implode("
", $this->sentTo('1001')));
        $this->assertTrue($fine->fresh()->canAppeal(), 'A valid reason can still be given');

        $second = $people->appealPenalty($fine->fresh()->load('employee'), $this->employee->user, 'Bike accident on the way, hospital slip attached');
        $this->assertSame('pending', $second->status);
        $this->assertSame($second->id, $fine->fresh()->appeal->id);
        $this->assertFalse($fine->fresh()->canAppeal());
    }

    public function test_bot_is_set_up_in_the_hrms_token_encrypted_and_never_shown(): void
    {
        $this->assertFalse(app(TelegramNotificationService::class)->configured(), 'Nothing in .env');

        Livewire::actingAs($this->admin)->test(Telegram::class)
            ->set('botToken', 'not-a-token')->call('saveBot')->assertHasErrors('botToken')
            ->set('botToken', self::TOKEN)->set('adminChatId', '1001')->call('saveBot')->assertHasNoErrors()
            ->assertSet('botToken', '')->assertDontSee(self::TOKEN)->assertDontSee('AAHdqTcv')->assertSee('A token is saved')->assertSee('from HRMS');

        $raw = DB::table('telegram_settings')->value('bot_token');
        $this->assertNotSame(self::TOKEN, $raw, 'Stored encrypted');
        $this->assertSame(self::TOKEN, TelegramSetting::current()->bot_token);
        $this->assertArrayNotHasKey('bot_token', TelegramSetting::current()->toArray());
        $telegram = app(TelegramNotificationService::class);
        $this->assertTrue($telegram->enabled());
        $this->assertSame('1001', $telegram->adminChatId());
        $this->assertSame('sws_hrms_bot', $telegram->botUsername());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/setWebhook'));

        // Saving again with an empty token keeps it; turning the bot off stops every message.
        Livewire::actingAs($this->admin)->test(Telegram::class)->set('botEnabled', false)->call('saveBot')->assertHasNoErrors();
        $this->assertSame(self::TOKEN, TelegramSetting::current()->bot_token);
        $this->assertFalse(app(TelegramNotificationService::class)->enabled());

        Livewire::actingAs($this->employee->user)->test(Telegram::class)->call('saveBot')->assertForbidden();
    }

    public function test_use_my_telegram_fills_the_admin_chat(): void
    {
        TelegramLink::create(['user_id' => $this->admin->id, 'chat_id' => '4242', 'linked_at' => now()]);
        Livewire::actingAs($this->admin)->test(Telegram::class)->call('useMyChat')->assertSet('adminChatId', '4242');
    }

    public function test_overtime_and_expense_requests_reach_the_super_admin(): void
    {
        $this->bot();
        \App\Models\OvertimeRequest::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-27', 'requested_minutes' => 90, 'reason' => 'Release night', 'status' => 'pending']);
        \App\Models\ExpenseClaim::create(['employee_id' => $this->employee->id, 'category' => 'travel', 'spent_on' => '2026-10-27', 'amount' => 350, 'description' => 'Cab to client office', 'status' => 'pending']);
        $admin = $this->sentTo('1001');
        $this->assertStringContainsString('OVERTIME REQUESTED', $admin[0]);
        $this->assertStringContainsString('Overtime: 1 h 30 min', $admin[0]);
        $this->assertStringContainsString('EXPENSE CLAIM SUBMITTED', $admin[1]);
        $this->assertStringContainsString('Amount: Rs 350.00', $admin[1]);
    }
}
