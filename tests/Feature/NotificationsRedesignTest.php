<?php

namespace Tests\Feature;

use App\Livewire\System\Notifications;
use App\Models\AttendanceEmployee;
use App\Models\User;
use App\Services\AttendanceCorrectionService;
use App\Support\NotificationPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** Notifications: type colours, bold names, approve from the notification, filters and the app API. */
class NotificationsRedesignTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->manager = User::factory()->create(['email' => 'nf-mgr@example.com', 'name' => 'Mona Manager']);
        $this->manager->assignRole('Manager');
        $boss = AttendanceEmployee::create(['employee_code' => 'NF-M', 'name' => 'Mona Manager', 'is_active' => true, 'user_id' => $this->manager->id, 'joined_at' => '2026-01-01']);
        $user = User::factory()->create(['email' => 'nf-emp@example.com', 'name' => 'Abheeshri Parmar']);
        $user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'NF-E', 'name' => 'Abheeshri Parmar', 'is_active' => true, 'user_id' => $user->id, 'manager_id' => $boss->id, 'joined_at' => '2026-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_correction_shows_name_in_bold_and_is_approved_from_the_notification(): void
    {
        app(AttendanceCorrectionService::class)->request($this->employee, '2026-10-24', '10:18', null, 'Setting up new system, counted as break');
        $item = collect((new NotificationPresenter($this->manager))->present($this->manager->notifications()->get()))->firstWhere('kind', 'AttendanceCorrection');
        $this->assertSame('correction', $item['type']);
        $this->assertSame(['Abheeshri Parmar', true], [$item['parts'][0]['text'], $item['parts'][0]['bold']]);
        $this->assertStringContainsString('requested attendance correction for 24 Oct', $item['title']);
        $this->assertSame('Setting up new system, counted as break', $item['quote']);
        $this->assertTrue($item['needs_action']);
        $this->assertSame('#FAEEDA', $item['bg']);

        Livewire::actingAs($this->manager)->test(Notifications::class)
            ->assertSee('1 needs your action')->set('filter', 'action')->assertSee('Approve')
            ->call('approve', $item['approval_id'], $item['id'])
            ->set('filter', 'all')->assertSee('Approved by you')->assertSee('0 need your action');
        $this->assertNotNull($this->manager->notifications()->whereKey($item['id'])->first()->read_at);

        // Rejecting a correction without a reason keeps the dialog open with the message.
        app(AttendanceCorrectionService::class)->request($this->employee, '2026-10-23', '10:00', null, 'Second one');
        $second = collect((new NotificationPresenter($this->manager))->needsAction())->first();
        Livewire::actingAs($this->manager)->test(Notifications::class)
            ->call('askReject', $second['approval_id'], $second['id'])->call('confirmReject')->assertHasErrors('rejectNote')->assertSet('rejectApproval', $second['approval_id'])
            ->set('rejectNote', 'Wrong day')->call('confirmReject')->assertHasNoErrors()->assertSet('rejectApproval', null)->assertSee('Rejected by you');
    }

    public function test_reject_needs_a_reason_and_the_app_gets_the_same_fields(): void
    {
        app(AttendanceCorrectionService::class)->request($this->employee, '2026-10-24', '10:18', null, 'Forgot');
        Sanctum::actingAs($this->manager, ['*']);
        $json = $this->getJson('/api/notifications?filter=action')->assertOk()->json();
        $this->assertSame(1, $json['meta']['counts']['needs_action']);
        $this->assertSame('clock-edit-outline', $json['data'][0]['app_icon']);
        // Rejecting needs a reason (existing rule).
        $this->postJson('/api/approvals/'.$json['data'][0]['approval_id'].'/decision', ['approve' => false])->assertStatus(422);
        $this->postJson('/api/approvals/'.$json['data'][0]['approval_id'].'/decision', ['approve' => false, 'note' => 'Not this time'])->assertOk();
        $this->assertSame(0, $this->getJson('/api/notifications')->json('meta.counts.needs_action'));
    }

    public function test_old_durations_are_written_in_words_and_late_teammates_are_grouped(): void
    {
        $this->assertSame('X checked in late at 10:05 AM · 35 min late.', NotificationPresenter::tidy('X checked in late today at 10:05 AM (00:35:00 after the start time).'));
        foreach (['Abheeshri Parmar', 'Mona Manager'] as $name) {
            \App\Services\Notifier::send($this->manager, 'AttendanceLate', 1, "{$name} checked in late at 10:15 AM · 3 min late.");
        }
        $p = new NotificationPresenter($this->manager);
        $rows = $p->group($p->present($this->manager->notifications()->latest()->get()));
        $this->assertCount(1, $rows);
        $this->assertSame('2 teammates checked in late', $rows[0]['title']);
    }

    public function test_bell_shows_99_plus_only_above_99_and_mark_all_read(): void
    {
        foreach (range(1, 100) as $i) \App\Services\Notifier::send($this->manager, 'Announcement', $i, "News {$i}");
        $this->actingAs($this->manager)->get('/notifications')->assertSee('99+');
        $this->post('/notifications/read-all')->assertRedirect();
        $this->assertSame(0, $this->manager->unreadNotifications()->count());
    }
}
