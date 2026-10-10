<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\AttendanceEmployee;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\WhatsAppNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-12 09:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_disabled_by_default_sends_nothing(): void
    {
        Queue::fake();
        $mgrUser = User::factory()->create();
        $mgr = AttendanceEmployee::create(['employee_code' => 'M1', 'name' => 'Mgr', 'is_active' => true, 'user_id' => $mgrUser->id, 'phone' => '9876500000']);
        $emp = AttendanceEmployee::create(['employee_code' => 'E1', 'name' => 'Emp', 'is_active' => true, 'manager_id' => $mgr->id, 'work_start' => '09:30:00', 'work_end' => '18:30:00']);
        app(AttendanceService::class)->checkIn($emp, 'web');
        $this->app->terminate();
        Queue::assertNothingPushed();
    }

    public function test_check_in_messages_the_manager_and_fixed_numbers_when_enabled(): void
    {
        config(['services.whatsapp.enabled' => true, 'services.whatsapp.provider' => 'meta', 'services.whatsapp.token' => 't', 'services.whatsapp.phone_id' => 'p',
            'services.whatsapp.notify_manager' => true, 'services.whatsapp.default_cc' => '91',
            'services.whatsapp.fixed' => ['+91 90000 11111'], 'services.whatsapp.events' => ['check_in', 'leave_applied']]);
        Queue::fake();
        $mgrUser = User::factory()->create();
        $mgr = AttendanceEmployee::create(['employee_code' => 'M2', 'name' => 'Mgr Two', 'is_active' => true, 'user_id' => $mgrUser->id, 'phone' => '9876500000']);
        $emp = AttendanceEmployee::create(['employee_code' => 'E2', 'name' => 'Asha', 'is_active' => true, 'manager_id' => $mgr->id, 'work_start' => '09:30:00', 'work_end' => '18:30:00']);

        app(AttendanceService::class)->checkIn($emp, 'web');
        $this->app->terminate();

        // Manager (10-digit -> +91) and the fixed number (already with CC).
        Queue::assertPushed(SendWhatsAppMessage::class, fn ($job) => $job->to === '919876500000' && str_contains($job->text, 'Asha') && str_contains($job->text, 'checked in'));
        Queue::assertPushed(SendWhatsAppMessage::class, fn ($job) => $job->to === '919000011111');
        Queue::assertPushed(SendWhatsAppMessage::class, 2);
    }

    public function test_job_posts_to_meta_cloud_api(): void
    {
        config(['services.whatsapp.enabled' => true, 'services.whatsapp.provider' => 'meta', 'services.whatsapp.token' => 'TKN', 'services.whatsapp.phone_id' => '123']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        (new SendWhatsAppMessage('919876500000', 'Hello'))->handle();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/123/messages')
            && $r['to'] === '919876500000' && $r['text']['body'] === 'Hello'
            && $r->hasHeader('Authorization', 'Bearer TKN'));
    }

    public function test_job_does_nothing_when_disabled(): void
    {
        config(['services.whatsapp.enabled' => false]);
        Http::fake();
        (new SendWhatsAppMessage('919876500000', 'Hi'))->handle();
        Http::assertNothingSent();
    }
}
