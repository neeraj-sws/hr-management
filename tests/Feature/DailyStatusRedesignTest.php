<?php

namespace Tests\Feature;

use App\Livewire\Work\DailyStatus;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\DailyWorkStatus;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Daily Work Status: summary cards, filters, tabs, missing list, View details drawer and its scope. */
class DailyStatusRedesignTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $managerUser;
    private AttendanceEmployee $a;
    private AttendanceEmployee $b;
    private AttendanceEmployee $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-21 16:00:00'); // a Wednesday
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $dev = Department::create(['name' => 'Dev Team', 'code' => 'DVT']);
        $this->managerUser = User::factory()->create();
        $this->managerUser->assignRole('Manager');
        $boss = AttendanceEmployee::create(['employee_code' => 'DS-M', 'name' => 'Boss Person', 'is_active' => true, 'user_id' => $this->managerUser->id, 'joined_at' => '2026-01-01']);
        $this->a = AttendanceEmployee::create(['employee_code' => 'DS-A', 'name' => 'Khushboo Verma', 'is_active' => true, 'manager_id' => $boss->id, 'department_id' => $dev->id, 'joined_at' => '2026-01-01', 'work_end' => '19:00:00']);
        $this->b = AttendanceEmployee::create(['employee_code' => 'DS-B', 'name' => 'Fahad Shaikh', 'is_active' => true, 'manager_id' => $boss->id, 'joined_at' => '2026-01-01']);
        $this->outsider = AttendanceEmployee::create(['employee_code' => 'DS-X', 'name' => 'Other Team', 'is_active' => true, 'joined_at' => '2026-01-01']);

        DailyWorkStatus::create(['employee_id' => $this->a->id, 'work_date' => '2026-10-21', 'plan' => 'Leave module', 'summary' => 'Approval fixed', 'blockers' => 'Minor UI issue in mobile view', 'submitted_at' => now()->setTime(10, 24)]);
        DailyWorkStatus::create(['employee_id' => $this->a->id, 'work_date' => '2026-10-20', 'summary' => 'Monday work', 'submitted_at' => now()->subDay()]);
        DailyWorkStatus::create(['employee_id' => $this->outsider->id, 'work_date' => '2026-10-21', 'summary' => 'Secret outsider work', 'submitted_at' => now()]);
        // Fahad checked in today but wrote nothing.
        AttendanceSession::create(['employee_id' => $this->b->id, 'work_date' => '2026-10-21', 'check_in' => '2026-10-21 09:30:00', 'status' => 'working']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cards_filters_tabs_and_missing_for_hr(): void
    {
        $page = Livewire::actingAs($this->admin)->test(DailyStatus::class)
            ->assertSee('Team updates')->assertSee('Missing today')->assertSee('Fahad Shaikh')->assertSee('Minor UI issue in mobile view')
            ->assertViewHas('cards', fn ($c) => $c['submitted'] === 2 && $c['missing'] === 1 && $c['blockers'] === 1)
            ->assertViewHas('periodCounts', fn ($c) => $c['today'] === 2 && $c['yesterday'] === 1);
        $page->set('blockerFilter', 'yes')->assertSee('Approval fixed')->assertDontSee('Secret outsider work');
        $page->call('resetFilters')->set('department', Department::where('code', 'DVT')->value('id'))->assertSee('Monday work')->assertDontSee('Secret outsider work');
        $page->call('resetFilters')->set('search', 'outsider')->assertSee('Secret outsider work')->assertDontSee('Approval fixed');
        $page->call('resetFilters')->set('period', 'yesterday')->assertSee('Monday work')->assertDontSee('Approval fixed');
    }

    public function test_view_details_tabs_and_day_navigation(): void
    {
        $today = DailyWorkStatus::where('employee_id', $this->a->id)->whereDate('work_date', '2026-10-21')->firstOrFail();
        $page = Livewire::actingAs($this->managerUser)->test(DailyStatus::class)->call('view', $today->id)
            ->assertSee("Today's plan", false)->assertSee('Leave module')->assertSee('Blockers / issues')->assertSee('Submission');
        foreach (['timesheet', 'tasks', 'projects', 'attendance', 'leaves', 'activity'] as $tab) $page->call('showTab', $tab)->assertSet('viewTab', $tab)->assertHasNoErrors();
        $page->call('showTab', 'work')->call('stepDay', -1)->assertSee('Monday work')->call('stepDay', 1)->assertSee('Approval fixed')->call('closeView')->assertSet('viewing', null);
    }

    public function test_manager_cannot_open_someone_outside_the_team_or_remind_them(): void
    {
        $other = DailyWorkStatus::where('employee_id', $this->outsider->id)->firstOrFail();
        Livewire::actingAs($this->managerUser)->test(DailyStatus::class)->assertDontSee('Secret outsider work')->call('view', $other->id)->assertForbidden();
        Livewire::actingAs($this->managerUser)->test(DailyStatus::class)->call('remindOne', $this->outsider->id)->assertForbidden();
        Livewire::actingAs($this->managerUser)->test(DailyStatus::class)->call('remindOne', $this->b->id)->assertHasNoErrors();
    }

    public function test_form_opens_from_the_button_and_end_of_day_summary_goes_to_person_and_manager(): void
    {
        $userA = User::factory()->create(['email' => 'khushboo@company.test']);
        $userA->assignRole('Employee');
        $this->a->update(['user_id' => $userA->id]);
        Livewire::actingAs($userA)->test(DailyStatus::class)->assertSet('showStatusForm', false)->call('openStatusForm')->assertSet('showStatusForm', true)->assertSee('Plan for today');
        $this->actingAs($userA)->get(route('work.daily-status', ['write' => 1]))->assertOk()->assertSee('Plan for today');

        \Illuminate\Support\Facades\Notification::fake();
        $this->artisan('work:daily-status-summary')->assertSuccessful();
        \Illuminate\Support\Facades\Notification::assertSentTo($userA, \App\Notifications\DailyStatusSummary::class);
        \Illuminate\Support\Facades\Notification::assertSentTo($this->managerUser, \App\Notifications\DailyStatusSummary::class);
        $status = \App\Models\DailyWorkStatus::where('employee_id', $this->a->id)->whereDate('work_date', '2026-10-21')->firstOrFail();
        $mail = (new \App\Notifications\DailyStatusSummary($status, ['worked' => 3600, 'break' => 600, 'idle' => 0, 'task_seconds' => 1800, 'check_in' => '10:00 AM', 'check_out' => null]))->toMail($this->managerUser);
        $this->assertStringContainsString('Khushboo Verma', $mail->subject);
        $this->assertStringContainsString('worked 01:00:00', implode(' ', $mail->introLines));
    }

    public function test_end_of_day_summary_also_goes_to_the_address_in_settings(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->artisan('work:daily-status-summary')->assertSuccessful();
        $before = count(\Illuminate\Support\Facades\Notification::sentNotifications()[\App\Support\MailRecipient::class] ?? []);
        $this->assertSame(0, $before);

        Livewire::actingAs($this->admin)->test(\App\Livewire\System\Settings::class)->set('tab', 'email')
            ->set('dailySummaryTo', 'not-an-email')->call('saveEmail')->assertHasErrors('dailySummaryTo')
            ->set('dailySummaryTo', 'swstask@gmail.com')->call('saveEmail')->assertHasNoErrors()->assertSee('End-of-day summary also to');

        \Illuminate\Support\Facades\Notification::fake();
        $this->artisan('work:daily-status-summary')->assertSuccessful();
        $statuses = \App\Models\DailyWorkStatus::whereDate('work_date', today())->count();
        $extra = collect(\Illuminate\Support\Facades\Notification::sentNotifications()[\App\Support\MailRecipient::class] ?? [])->flatten(2);
        $this->assertGreaterThan(0, $statuses);
        $this->assertCount($statuses, $extra, 'One copy of every summary to swstask@gmail.com');
        $this->assertStringContainsString('and to swstask@gmail.com', collect(\App\Support\Instructions::sections())->firstWhere('key', 'work')['items'][1][1]);
    }

    public function test_daily_status_reminder_pops_up_on_the_website(): void
    {
        $user = User::factory()->create(['email' => 'popup@company.test']);
        $user->assignRole('Employee');
        $since = now()->subMinute()->toIso8601String();
        $user->notify(new \App\Notifications\ApprovalRequested('DailyStatusReminder', 1, 'Please submit your daily work status for 21 Oct.', '/work/daily-status'));
        $data = $this->actingAs($user)->getJson(route('system.notifications.live', ['since' => $since]))->assertOk()->json();
        $this->assertSame('Please submit your daily work status for 21 Oct.', $data['urgent'][0]['title']);
        $this->actingAs($user)->get(route('dashboard'))->assertSee('nf-urgent', false);
    }
}
