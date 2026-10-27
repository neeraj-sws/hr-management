<?php

namespace Tests\Feature;

use App\Livewire\Work\DailyStatus;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\User;
use App\Services\WorkService;
use App\Support\DailyStatusRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Who must write a daily status (admin choice per person + company default), and reminders based on it. */
class DailyStatusRuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $must;
    private AttendanceEmployee $quiet;
    private AttendanceEmployee $free;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-24 18:15:00'); // a Saturday that is worked
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        app(\App\Services\PeopleOperationsService::class)->setPolicy('calendar.working_days', 'calendar', 'Working calendar', ['days' => [1, 2, 3, 4, 5, 6], 'holidays' => []], null, $this->admin->id, '2026-01-01');
        $make = function (string $code, string $name) {
            $user = User::factory()->create(['email' => strtolower($code).'@company.test']);
            $user->assignRole('Employee');
            $e = AttendanceEmployee::create(['employee_code' => $code, 'name' => $name, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01']);
            AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-24', 'check_in' => '2026-10-24 10:00:00', 'status' => 'working']);

            return $e;
        };
        $this->must = $make('DR-1', 'Must Write');
        $this->quiet = $make('DR-2', 'No Reminder');
        $this->free = $make('DR-3', 'Not Compulsory');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_admin_sets_who_must_write_it_and_reminders_follow(): void
    {
        $this->assertSame('required_reminder', DailyStatusRule::mode($this->must), 'Default: compulsory + reminder');
        $page = Livewire::actingAs($this->admin)->test(DailyStatus::class)->call('openRules')->assertSee('Who must write it')
            ->call('setRule', $this->quiet->id, 'required')
            ->call('setRule', $this->free->id, 'optional');
        $this->assertSame('required', DailyStatusRule::mode($this->quiet));
        $this->assertSame('optional', DailyStatusRule::mode($this->free));

        // 6:15 PM automatic reminder, on a Saturday too: only "compulsory + reminder".
        $this->assertSame(1, app(WorkService::class)->remindDailyStatus('2026-10-24'));
        $this->assertSame(1, $this->must->user->notifications()->where('data->kind', 'DailyStatusReminder')->count());
        $this->assertSame(0, $this->quiet->user->notifications()->count());
        $this->assertSame(0, $this->free->user->notifications()->count());

        // "Remind" button: everyone compulsory (with or without the automatic one); never the optional person.
        $this->assertSame(2, app(WorkService::class)->remindDailyStatus('2026-10-24', null, $this->admin));
        $this->assertSame(0, $this->free->user->notifications()->count());

        // Missing list: the optional person is never missing.
        $page->call('closeRules')->assertSee('Must Write')->assertSee('No Reminder')->assertViewHas('missing', fn ($m) => $m->pluck('id')->sort()->values()->all() === collect([$this->must->id, $this->quiet->id])->sort()->values()->all());

        // Back to the company default; the default itself can be changed for everyone.
        $page->call('setRule', $this->free->id, 'default');
        $this->assertSame('required_reminder', DailyStatusRule::mode($this->free));
        $page->call('setDefaultRule', 'optional');
        $this->assertSame('optional', DailyStatusRule::mode($this->free));
        $page->call('setRuleForAll', 'required_reminder');
        $this->assertSame('required_reminder', DailyStatusRule::mode($this->free));
    }

    public function test_only_hr_or_admin_can_change_it(): void
    {
        Livewire::actingAs($this->must->user)->test(DailyStatus::class)->call('openRules')->assertForbidden();
        Livewire::actingAs($this->must->user)->test(DailyStatus::class)->call('setRule', $this->free->id, 'optional')->assertForbidden();
    }

    public function test_profile_option_and_app_missed_days(): void
    {
        Livewire::actingAs($this->admin)->test(\App\Livewire\People\EmployeeProfile::class, ['employee' => $this->free])
            ->assertSee('Daily status')->call('setDailyStatusRule', 'optional');
        $this->assertSame('optional', DailyStatusRule::mode($this->free));

        Carbon::setTestNow('2026-10-25 12:00:00');
        \Laravel\Sanctum\Sanctum::actingAs($this->free->user);
        $r = $this->getJson('/api/daily-status?month=2026-10')->assertOk()->json('month_summary');
        $this->assertSame([], $r['missed'], 'Not compulsory: no missed days');
        $this->assertFalse($r['required']);
        \Laravel\Sanctum\Sanctum::actingAs($this->must->user);
        $this->assertSame(['2026-10-24'], $this->getJson('/api/daily-status?month=2026-10')->json('month_summary.missed'));
    }
}
