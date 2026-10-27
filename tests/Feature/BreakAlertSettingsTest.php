<?php

namespace Tests\Feature;

use App\Livewire\System\Settings;
use App\Models\User;
use App\Services\AttendanceAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings > Work hours: the break alert tick box and long-break minutes load and save. */
class BreakAlertSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_break_alert_settings_load_and_save(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $page = Livewire::actingAs($admin)->test(Settings::class)
            ->assertSet('breakAlerts', true)->assertSet('longBreakMinutes', '60');

        $page->set('breakAlerts', false)->set('longBreakMinutes', '45')->call('saveHours')->assertHasNoErrors();
        $this->assertFalse(app(AttendanceAlertService::class)->settings()['break_alerts']);
        $this->assertSame(45, app(AttendanceAlertService::class)->settings()['long_break_minutes']);

        Livewire::actingAs($admin)->test(Settings::class)->assertSet('breakAlerts', false)->assertSet('longBreakMinutes', '45')
            ->set('breakAlerts', true)->call('saveHours')->assertHasNoErrors();
        $this->assertTrue(app(AttendanceAlertService::class)->settings()['break_alerts']);
    }
}
