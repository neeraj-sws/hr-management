<?php

namespace Tests\Feature;

use App\Livewire\Hr\Dashboard;
use App\Livewire\Monitoring\Policy;
use App\Livewire\System\Settings;
use App\Models\AttendanceEmployee;
use App\Models\User;
use App\Notifications\Channels\SafeMailChannel;
use App\Notifications\ScreenCaptureNotice;
use App\Services\PeopleOperationsService;
use App\Services\ScreenCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Screen capture transparency: notice (in-app + e-mail), policy page, dashboard, acknowledgment and the admin view. */
class ScreenCaptureNoticeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $user;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->user = User::factory()->create(['email' => 'watched@example.com']);
        $this->user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'SC-1', 'name' => 'Seema Capture', 'is_active' => true, 'user_id' => $this->user->id, 'joined_at' => '2026-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_turning_capture_on_notifies_once_in_app_and_by_email_and_again_when_terms_change(): void
    {
        Notification::fake();
        $captures = app(ScreenCaptureService::class);
        $captures->setForEmployee($this->employee, true, $this->admin);
        Notification::assertSentTo($this->user, ScreenCaptureNotice::class, function (ScreenCaptureNotice $n, array $channels) {
            $mail = $n->toMail($this->user);
            $text = implode(' ', $mail->introLines);

            return $channels === ['database', SafeMailChannel::class]
                && str_contains($text, 'every 10 minutes') && str_contains($text, 'Why: '.ScreenCaptureService::DEFAULT_PURPOSE)
                && str_contains($text, 'Who can see the screenshots') && str_contains($text, 'deleted automatically after 30 days')
                && str_contains($n->toDatabase($this->user)['message'], 'Screen capture is on for you');
        });
        $this->assertSame(0, $captures->notifyAll(), 'Told once per version of the terms');

        Livewire::actingAs($this->admin)->test(Settings::class)->set('tab', 'capture')
            ->set('captureInterval', '15')->set('capturePurpose', 'Client billing proof')->call('saveCapturePolicy')->assertHasNoErrors();
        Notification::assertSentToTimes($this->user, ScreenCaptureNotice::class, 2);
        $this->assertStringContainsString('every 15 minutes', $captures->describe($captures->policy($this->employee))['frequency']);
        $this->assertSame('Client billing proof', $captures->policy($this->employee)['purpose']);
    }

    public function test_a_new_employee_joining_while_capture_is_on_for_everyone_is_told(): void
    {
        Notification::fake();
        app(PeopleOperationsService::class)->setPolicy('monitoring.screenshots', 'monitoring', 'Screen capture', ['enabled' => true, 'interval_minutes' => 10], null, $this->admin->id, '2026-10-01');
        $employee = app(\App\Services\EmployeeService::class)->create(['name' => 'Neha New', 'email' => 'neha.new@example.com', 'password' => 'Secret123!', 'employee_code' => 'SC-2', 'joined_at' => '2026-10-14'], $this->admin);
        Notification::assertSentTo($employee->user, ScreenCaptureNotice::class);
    }

    public function test_employee_sees_and_confirms_the_policy_and_admin_sees_who_confirmed(): void
    {
        app(ScreenCaptureService::class)->setForEmployee($this->employee, true, $this->admin);
        Livewire::actingAs($this->user)->test(Dashboard::class)
            ->assertSee('Screen monitoring is ON for you')->assertSee('every 10 minutes')->assertSee('Read the monitoring policy and confirm');
        $this->actingAs($this->user, 'sanctum')->getJson('/api/me')
            ->assertJsonPath('data.policies.screen_capture.acknowledged', false)
            ->assertJsonPath('data.policies.screen_capture.notice.purpose', ScreenCaptureService::DEFAULT_PURPOSE)
            ->assertJsonPath('data.policies.screen_capture.policy_url', route('monitoring.policy'));
        Livewire::actingAs($this->admin)->test(Settings::class)->set('tab', 'capture')->assertSee('not yet');

        Livewire::actingAs($this->user)->test(Policy::class)
            ->assertSee('Screen capture is ON for you')->assertSee('Who can see them')->assertSee('Never recorded')
            ->assertSee('I have read and understood this policy')->call('acknowledge')->assertDontSee('I have read and understood this policy');
        $this->actingAs($this->user, 'sanctum')->getJson('/api/me')->assertJsonPath('data.policies.screen_capture.acknowledged', true);
        Livewire::actingAs($this->user)->test(Dashboard::class)->assertSee('Monitoring policy')->assertDontSee('and confirm');
        Livewire::actingAs($this->admin)->test(Settings::class)->set('tab', 'capture')->assertSee('acknowledged');

        // New terms must be confirmed again.
        app(PeopleOperationsService::class)->setPolicy('monitoring.screenshots', 'monitoring', 'Screen capture', ['enabled' => false, 'interval_minutes' => 5], null, $this->admin->id, '2026-10-14');
        $this->actingAs($this->user, 'sanctum')->getJson('/api/me')->assertJsonPath('data.policies.screen_capture.acknowledged', false);
        $this->postJson('/api/monitoring/acknowledge')->assertOk();
        $this->getJson('/api/me')->assertJsonPath('data.policies.screen_capture.acknowledged', true);
    }

    public function test_policy_page_says_capture_is_off_when_it_is(): void
    {
        $this->actingAs($this->user)->get(route('monitoring.policy'))->assertOk()->assertSee('Screen capture is OFF for you')->assertDontSee('I have read and understood');
        Livewire::actingAs($this->user)->test(Dashboard::class)->assertDontSee('Screen monitoring is ON');
    }
}
