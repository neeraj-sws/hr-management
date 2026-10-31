<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\TelegramLink;
use App\Models\Task;
use App\Models\User;
use App\Services\NightWorkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Requests still waiting for approval: a Telegram list to the Super Admin and to the employee's manager, once a day each. */
class PendingApprovalTelegramRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sentTo(string $chat): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_contains($r->url(), '/sendMessage') && (string) ($r['chat_id'] ?? '') === $chat)->map(fn (Request $r) => $r['text'])->values()->all();
    }

    public function test_super_admin_and_manager_are_reminded_once_a_day(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-10-30 09:00:00');
        config(['services.telegram' => ['enabled' => true, 'bot_token' => '123456:TEST', 'bot_username' => 'sws_hrms_bot', 'admin_chat_id' => '1001', 'webhook_secret' => 'x', 'queue_connection' => 'sync', 'link_minutes' => 10]]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $make = function (string $code, string $name, ?int $managerId = null) {
            $user = User::factory()->create(['name' => $name]);
            $user->assignRole('Employee');

            return AttendanceEmployee::create(['employee_code' => $code, 'name' => $name, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'manager_id' => $managerId, 'work_start' => '10:00:00', 'work_end' => '18:00:00']);
        };
        $manager = $make('MGR-1', 'Team Lead');
        $employee = $make('EMP-1', 'Riya Dev', $manager->id);
        TelegramLink::create(['user_id' => $manager->user_id, 'chat_id' => '2002', 'linked_at' => now()]);
        $task = Task::create(['title' => 'Live search bug', 'assignee_id' => $employee->id, 'created_by' => $employee->user_id, 'status' => 'in_progress', 'priority' => 'high']);
        app(NightWorkService::class)->request($employee, '2026-10-29', '22:00', '23:30', [$task->id], 'Fixed the live search bug at night');

        // 10 AM: waited only 1 hour - not yet.
        Carbon::setTestNow('2026-10-30 10:00:00');
        $this->artisan('approvals:pending-reminders')->assertSuccessful();
        $this->assertCount(0, collect($this->sentTo('2002'))->filter(fn ($t) => str_contains($t, 'WAITING FOR YOUR APPROVAL')));

        // 11 AM: 2 hours - both reminded; again at 12: not repeated the same day.
        Carbon::setTestNow('2026-10-30 11:05:00');
        $this->artisan('approvals:pending-reminders')->assertSuccessful();
        Carbon::setTestNow('2026-10-30 12:05:00');
        $this->artisan('approvals:pending-reminders')->assertSuccessful();
        $admin = collect($this->sentTo('1001'))->filter(fn ($t) => str_contains($t, 'WAITING FOR APPROVAL'))->values();
        $manager = collect($this->sentTo('2002'))->filter(fn ($t) => str_contains($t, 'WAITING FOR YOUR APPROVAL'))->values();
        $this->assertCount(1, $admin);
        $this->assertCount(1, $manager);
        $this->assertStringContainsString('1. Night work: Riya Dev', $manager[0]);
        $this->assertStringContainsString('waiting 2 h', $manager[0]);

        // Next day, still undecided: reminded again.
        Carbon::setTestNow('2026-10-31 10:05:00');
        $this->artisan('approvals:pending-reminders')->assertSuccessful();
        $this->assertCount(2, collect($this->sentTo('2002'))->filter(fn ($t) => str_contains($t, 'WAITING FOR YOUR APPROVAL')));
    }
}
