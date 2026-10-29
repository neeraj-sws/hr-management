<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramNotification;
use App\Models\AttendanceEmployee;
use App\Models\LeaveType;
use App\Models\Task;
use App\Models\TelegramLink;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\HrWorkflowService;
use App\Services\TelegramNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Telegram: the Super Admin gets HR events in one chat; each employee gets only their own notifications in the chat
 * they linked with a single-use, short-lived token. Sent after commit, once per message, never with the token shown.
 */
class TelegramNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456:TEST-SECRET-TOKEN';

    private User $admin;
    private AttendanceEmployee $employee;
    private TelegramNotificationService $telegram;

    /** What the fake Telegram answers (null = ok). */
    private mixed $reply = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-28 09:32:00');
        config(['services.telegram' => ['enabled' => true, 'bot_token' => self::TOKEN, 'bot_username' => 'sws_hrms_bot', 'admin_chat_id' => '1001', 'webhook_secret' => 'hook-secret', 'queue_connection' => 'sync', 'link_minutes' => 10]]);
        Http::fake(['api.telegram.org/*' => fn () => $this->reply ?? Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create(['email' => 'rahul@company.test', 'name' => 'Rahul Sharma']);
        $user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'EMP001', 'name' => 'Rahul Sharma', 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '19:00:00', 'daily_work_hours' => 8]);
        $this->telegram = app(TelegramNotificationService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function webhook(array $update, ?string $secret = 'hook-secret')
    {
        return $this->postJson('/api/telegram/webhook', $update, $secret ? ['X-Telegram-Bot-Api-Secret-Token' => $secret] : []);
    }

    private function start(string $text, string $chatId = '555', string $type = 'private')
    {
        return $this->webhook(['message' => ['chat' => ['id' => (int) $chatId, 'type' => $type], 'from' => ['username' => 'rahul_s'], 'text' => $text]]);
    }

    private function link(User $user, string $chatId = '555'): void
    {
        $this->start('/start '.$this->telegram->startLink($user)['token'], $chatId)->assertOk();
    }

    /** Messages sent to a chat, as text. */
    private function sentTo(string $chatId): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => str_contains($r->url(), '/sendMessage') && (string) ($r['chat_id'] ?? '') === $chatId)->map(fn (Request $r) => $r['text'])->values()->all();
    }

    public function test_linking_is_single_use_short_lived_and_cannot_take_another_account(): void
    {
        $link = $this->telegram->startLink($this->employee->user);
        $this->assertSame('https://t.me/sws_hrms_bot?start='.$link['token'], $link['url']);
        $this->assertNotSame($link['token'], TelegramLink::first()->token_hash, 'Only a hash is stored');

        $this->webhook(['message' => ['chat' => ['id' => 555, 'type' => 'private'], 'text' => '/start '.$link['token']]], null)->assertForbidden();
        $this->webhook(['message' => ['chat' => ['id' => 555, 'type' => 'private'], 'text' => '/start '.$link['token']]], 'wrong')->assertForbidden();
        $this->start('/start '.$link['token'], '777', 'group')->assertOk()->assertJsonMissing(['method' => 'sendMessage']);

        $this->start('/start '.$link['token'])->assertOk()->assertJson(['method' => 'sendMessage', 'chat_id' => '555'])->assertJsonFragment(['text' => '✅ Connected. You will get your SWS HRMS notifications here, Rahul Sharma.']);
        $this->assertSame('555', TelegramLink::where('user_id', $this->employee->user_id)->value('chat_id'));
        $this->start('/start '.$link['token'], '556')->assertJsonFragment(['text' => '⚠️ This link has expired or was already used. Open SWS HRMS > My profile > Connect Telegram and try again.']);
        $this->assertSame('555', TelegramLink::where('user_id', $this->employee->user_id)->value('chat_id'), 'A used token cannot move the account');

        // Another person cannot link the same Telegram chat; an expired token does nothing.
        $other = User::factory()->create();
        $this->start('/start '.$this->telegram->startLink($other)['token'], '555')->assertJsonFragment(['text' => '⚠️ This Telegram account is already connected to another SWS HRMS account. Disconnect it there first.']);
        $late = $this->telegram->startLink($other)['token'];
        Carbon::setTestNow(now()->addMinutes(11));
        $this->start('/start '.$late, '999');
        $this->assertNull(TelegramLink::where('user_id', $other->id)->value('chat_id'));

        // /start alone shows the chat ID (for the Super Admin's .env); /stop disconnects.
        $this->start('/start', '1001')->assertJsonFragment(['chat_id' => '1001'])->assertSee('Chat ID: 1001');
        $this->start('/stop')->assertSee('Disconnected');
        $this->assertNull(TelegramLink::where('user_id', $this->employee->user_id)->value('chat_id'));
    }

    public function test_check_in_and_check_out_reach_the_super_admin_and_the_employee_once(): void
    {
        $this->link($this->employee->user);
        $attendance = app(AttendanceService::class);
        $attendance->checkIn($this->employee, 'web');
        $admin = $this->sentTo('1001');
        $this->assertCount(1, $admin);
        $this->assertStringContainsString('<b>SWS HRMS | ATTENDANCE STARTED</b>', $admin[0]);
        $this->assertStringContainsString('Employee: Rahul Sharma', $admin[0]);
        $this->assertStringContainsString('Employee ID: EMP001', $admin[0]);
        $this->assertStringContainsString('Date: 28 October 2026', $admin[0]);
        $this->assertStringContainsString('Time: 09:32 AM', $admin[0]);
        $this->assertStringContainsString('Hello Rahul Sharma, your attendance is recorded.', $this->sentTo('555')[1] ?? $this->sentTo('555')[0]);

        Carbon::setTestNow('2026-10-28 18:05:00');
        $attendance->checkOut($this->employee, 'web');
        $this->assertStringContainsString('CHECKOUT COMPLETED', $this->sentTo('1001')[1]);
        $this->assertStringContainsString('Checkout Time: 06:05 PM', $this->sentTo('1001')[1]);

        // A retried job never sends the same message again.
        $key = TelegramMessage::where('target', 'admin')->where('event', 'check_in')->value('key');
        (new SendTelegramNotification('admin', null, $key, 'check_in', 'again'))->handle($this->telegram);
        $this->assertCount(2, $this->sentTo('1001'));
    }

    public function test_leave_goes_to_the_super_admin_and_the_decision_to_the_employee_only(): void
    {
        $this->link($this->employee->user);
        $annual = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        $workflow = app(HrWorkflowService::class);
        $leave = $workflow->requestLeave($this->employee, ['leave_type_id' => $annual->id, 'duration' => 'full', 'starts_on' => Carbon::parse('2026-10-29'), 'ends_on' => Carbon::parse('2026-10-30'), 'reason' => 'Family function'], false);
        $this->assertStringContainsString('NEW LEAVE REQUEST', implode("\n", $this->sentTo('1001')));
        $this->assertStringContainsString('Status: Pending Approval', implode("\n", $this->sentTo('1001')));

        $workflow->decideLeave($leave, $this->admin->id, true);
        $this->assertStringContainsString('LEAVE APPROVED', implode("\n", $this->sentTo('1001')));
        $own = implode("\n", $this->sentTo('555'));
        $this->assertStringContainsString('SWS HRMS Alert', $own);
        $this->assertStringContainsString('approved', strtolower($own));
        $this->assertSame([], collect(Http::recorded())->map(fn ($p) => (string) ($p[0]['chat_id'] ?? ''))->reject(fn ($c) => in_array($c, ['', '1001', '555'], true))->values()->all(), 'Nobody else gets it');
    }

    public function test_not_linked_employee_gets_nothing_and_no_extra_admin_copy(): void
    {
        app(AttendanceService::class)->checkIn($this->employee, 'web');
        $this->assertCount(1, $this->sentTo('1001'), 'Only the one administrative message');
        $this->assertSame(['1001'], collect(Http::recorded())->map(fn ($p) => (string) ($p[0]['chat_id'] ?? ''))->filter()->unique()->values()->all());
    }

    public function test_nothing_is_sent_for_a_rolled_back_change_or_when_switched_off(): void
    {
        try {
            DB::transaction(function () {
                Task::create(['title' => 'Rolled back task', 'assignee_id' => $this->employee->id, 'created_by' => $this->admin->id, 'status' => 'todo', 'priority' => 'medium']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame([], $this->sentTo('1001'));

        Task::create(['title' => 'Real task', 'assignee_id' => $this->employee->id, 'created_by' => $this->admin->id, 'status' => 'todo', 'priority' => 'medium']);
        $this->assertStringContainsString('Task: Real task', $this->sentTo('1001')[0]);

        $this->telegram->saveSettings([...$this->telegram->settings(), 'on' => false], $this->admin->id);
        app(AttendanceService::class)->checkIn($this->employee, 'web');
        $this->assertCount(1, $this->sentTo('1001'), 'Master switch off');
    }

    public function test_html_is_escaped_the_token_is_never_in_errors_and_a_blocked_bot_disconnects(): void
    {
        $this->assertStringContainsString('Employee: &lt;b&gt;Hacker&lt;/b&gt;', TelegramNotificationService::format('x', ['Employee' => '<b>Hacker</b>']));

        $this->link($this->employee->user);
        $this->reply = Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user (bot'.self::TOKEN.')'], 403);
        $this->telegram->toUser($this->employee->user, 'check_in', 'blocked-test', 'Hello');
        $row = TelegramMessage::where('key', 'like', '%blocked-test')->firstOrFail();
        $this->assertSame('failed', $row->status);
        $this->assertStringNotContainsString(self::TOKEN, (string) $row->error);
        $this->assertNull(TelegramLink::where('user_id', $this->employee->user_id)->value('chat_id'), 'Blocked bot = disconnected');
    }

    public function test_profile_connect_and_admin_page_access(): void
    {
        Livewire::actingAs($this->employee->user)->test(\App\Livewire\Account\Profile::class)
            ->assertSee('Connect Telegram')->call('connectTelegram')->assertSee('Open SWS HRMS bot in Telegram')->assertSee('https://t.me/sws_hrms_bot?start=', false)
            ->call('checkTelegram')->assertHasErrors('telegram');

        $this->actingAs($this->employee->user)->get(route('system.telegram'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('system.telegram'))->assertOk()->assertSee('Send test to Super Admin')->assertDontSee(self::TOKEN);
        Livewire::actingAs($this->admin)->test(\App\Livewire\System\Telegram::class)->call('sendTest')->assertHasNoErrors()
            ->set('form.admin.check_in', false)->call('save');
        $this->assertStringContainsString('TEST MESSAGE', $this->sentTo('1001')[0]);
        app(AttendanceService::class)->checkIn($this->employee, 'web');
        $this->assertCount(1, $this->sentTo('1001'), 'Check-in switched off for the Super Admin');
    }

    public function test_employee_messages_use_personal_templates(): void
    {
        $this->link($this->employee->user);
        $annual = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        $workflow = app(HrWorkflowService::class);
        $leave = $workflow->requestLeave($this->employee, ['leave_type_id' => $annual->id, 'duration' => 'full', 'starts_on' => Carbon::parse('2026-10-29'), 'ends_on' => Carbon::parse('2026-10-30'), 'reason' => 'Family function'], false);
        $workflow->decideLeave($leave, $this->admin->id, true, 'Enjoy');
        $own = collect($this->sentTo('555'))->first(fn ($t) => str_contains($t, 'LEAVE APPROVED'));
        $this->assertNotNull($own);
        foreach (['<b>SWS HRMS Alert</b>', '<b>SWS HRMS | LEAVE APPROVED</b>', 'Hello Rahul,', 'Your Annual Leave request for 2 day(s) has been approved.', 'Leave Type: Annual Leave', 'From: 29 October 2026', 'To: 30 October 2026', 'Note: Enjoy', 'Open SWS HRMS</a>'] as $part) {
            $this->assertStringContainsString($part, $own);
        }
    }

    public function test_task_changes_and_advance_payments_reach_the_employee(): void
    {
        $this->link($this->employee->user);
        $task = Task::create(['title' => 'Invoice screen', 'assignee_id' => $this->employee->id, 'created_by' => $this->admin->id, 'status' => 'todo', 'priority' => 'medium']);
        $this->actingAs($this->admin);
        $task->update(['status' => 'in_progress', 'due_on' => '2026-10-31']);
        $note = $this->employee->user->notifications()->where('data->kind', 'TaskUpdated')->firstOrFail();
        $this->assertStringContainsString('updated your task "Invoice screen": status: todo → in progress, due date: 31 Oct 2026.', $note->data['message']);
        $this->assertStringContainsString('<b>SWS HRMS | TASK UPDATED</b>', implode("\n", $this->sentTo('555')));

        // The employee changing their own task is not told about it.
        $this->actingAs($this->employee->user);
        $task->update(['status' => 'on_hold']);
        $this->assertSame(1, $this->employee->user->notifications()->where('data->kind', 'TaskUpdated')->count());

        $this->actingAs($this->admin);
        $advance = \App\Models\SalaryAdvance::create(['employee_id' => $this->employee->id, 'amount' => 5000, 'installment_amount' => 1000, 'disbursed_amount' => 0, 'recovered_amount' => 0, 'status' => 'approved', 'reason' => 'Rent', 'requested_on' => '2026-10-20', 'requested_by' => $this->employee->user_id]);
        app(\App\Services\PeopleOperationsService::class)->disburseAdvance($advance, 2000, $this->admin->id);
        $this->assertStringContainsString('Your salary advance payment of Rs 2,000.00 was paid on 28 Oct 2026.', $this->employee->user->notifications()->where('data->kind', 'SalaryAdvance')->firstOrFail()->data['message']);
        $own = implode("\n", $this->sentTo('555'));
        $this->assertStringContainsString('<b>SWS HRMS | ADVANCE PAYMENT RECORDED</b>', $own);
        $this->assertStringContainsString('Paid so far: Rs 2,000.00', $own);
        $this->assertStringContainsString('Amount: Rs 2,000.00', implode("\n", $this->sentTo('1001')), 'Super Admin: the amount paid now, not 0');
    }

    public function test_plain_text_option_and_extra_recipients_chosen_by_an_administrator(): void
    {
        $hr = User::factory()->create(['name' => 'Neha HR']);
        $this->link($hr, '777');
        Livewire::actingAs($this->admin)->test(\App\Livewire\System\Telegram::class)
            ->set('form.format', 'text')->set('form.extra_users', [(string) $hr->id, (string) $this->employee->user_id])->call('save')->assertHasNoErrors();
        $this->assertSame([$hr->id], $this->telegram->settings()['extra_users'], 'Only people who connected Telegram can be added');

        app(AttendanceService::class)->checkIn($this->employee, 'web');
        $admin = collect(Http::recorded())->map(fn ($p) => $p[0])->first(fn (Request $r) => str_contains($r->url(), '/sendMessage') && (string) $r['chat_id'] === '1001');
        $this->assertArrayNotHasKey('parse_mode', $admin->data());
        $this->assertStringStartsWith("SWS HRMS Alert\nSWS HRMS | ATTENDANCE STARTED", $admin['text']);
        $this->assertStringContainsString('ATTENDANCE STARTED', $this->sentTo('777')[0] ?? '', 'The added person gets the feed in their own chat');
        $this->assertSame('SWS HRMS Alert'."\n".'x: <b>y</b>'."\n\n".'Open SWS HRMS: https://hr.test/a', TelegramNotificationService::plain('<b>SWS HRMS Alert</b>'."\n".'x: &lt;b&gt;y&lt;/b&gt;'."\n\n".'<a href="https://hr.test/a">Open SWS HRMS</a>'));
    }

    public function test_app_connect_status_disconnect_and_preferences(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->employee->user, ['*']);
        $this->getJson('/api/me/telegram')->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.connected', false)->assertJsonPath('data.bot', 'sws_hrms_bot');
        $url = $this->postJson('/api/me/telegram/connect')->assertOk()->json('data.url');
        $this->assertStringStartsWith('https://t.me/sws_hrms_bot?start=', $url);
        $this->start('/start '.substr($url, strlen('https://t.me/sws_hrms_bot?start=')))->assertOk();
        \Laravel\Sanctum\Sanctum::actingAs($this->employee->user, ['*']);
        $this->getJson('/api/me/telegram')->assertJsonPath('data.connected', true)->assertJsonPath('data.username', 'rahul_s');

        // Preferences: the app shows the Telegram column; an older app (no telegram key) keeps the saved choice.
        $prefs = $this->getJson('/api/notifications/preferences')->assertJsonPath('telegram_available', true)->assertJsonPath('telegram_connected', true)->json('data');
        $this->assertTrue($prefs[0]['telegram']);
        $this->postJson('/api/notifications/preferences', ['preferences' => ['important' => ['in_app' => true, 'push' => true, 'email' => true, 'telegram' => false]]])->assertOk();
        $this->postJson('/api/notifications/preferences', ['preferences' => ['important' => ['in_app' => true, 'push' => true, 'email' => true]]])->assertOk();
        $this->assertFalse($this->employee->user->fresh()->notification_preferences['important']['telegram']);

        $this->postJson('/api/me/telegram/disconnect')->assertOk()->assertJsonPath('data.connected', false);
        $this->assertNull(TelegramLink::where('user_id', $this->employee->user_id)->value('chat_id'));
    }

    public function test_no_website_link_for_people_without_website_access(): void
    {
        app(\App\Services\PeopleOperationsService::class)->setPolicy(\App\Services\WebAccessService::POLICY, 'security', 'Website access', ['mode' => 'roles', 'roles' => ['HR'], 'user_ids' => []], null, $this->admin->id, '2026-01-01');
        $text = \App\Support\TelegramTemplates::forNotification('LeaveBalance', 1, 'Two days were added.', $this->employee->user, 'https://hr.test/leave');
        $this->assertStringContainsString('Open the SWS HR app for details.', $text);
        $this->assertStringNotContainsString('<a href', $text);
        $this->assertStringContainsString('Open SWS HRMS</a>', \App\Support\TelegramTemplates::forNotification('LeaveBalance', 1, 'x', $this->admin, 'https://hr.test/leave'));
    }

    public function test_connect_works_after_many_app_calls(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->employee->user, ['*']);
        for ($i = 0; $i < 15; $i++) $this->getJson('/api/me/telegram')->assertOk();
        $this->postJson('/api/me/telegram/connect')->assertOk();
    }
}
