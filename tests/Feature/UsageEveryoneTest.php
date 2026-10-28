<?php

namespace Tests\Feature;

use App\Livewire\System\Settings;
use App\Models\AttendanceEmployee;
use App\Models\User;
use App\Services\AppUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings > Screen capture: application usage and window titles for everyone, with or without replacing personal choices. */
class UsageEveryoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_titles_for_everyone(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $a = AttendanceEmployee::create(['employee_code' => 'UE-1', 'name' => 'Person A', 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01']);
        $b = AttendanceEmployee::create(['employee_code' => 'UE-2', 'name' => 'Person B', 'is_active' => true, 'joined_at' => '2026-01-01']);
        $usage = app(AppUsageService::class);
        $usage->setTitlesForEmployee($b, false, $admin); // B was switched off on the profile

        Livewire::actingAs($admin)->test(Settings::class)->set('tab', 'capture')->set('usageEveryone', true)->set('titlesEveryone', true)->call('saveUsagePolicy')->assertHasNoErrors();
        $this->assertTrue($usage->enabledFor($a) && $usage->titlesEnabledFor($a), 'Everyone without a personal choice');
        $this->assertFalse($usage->titlesEnabledFor($b), "B's own choice kept");
        Sanctum::actingAs($user);
        $this->getJson('/api/me')->assertJsonPath('data.policies.app_usage', true)->assertJsonPath('data.policies.window_titles', true);

        Livewire::actingAs($admin)->test(Settings::class)->set('tab', 'capture')->assertSet('usageEveryone', true)->assertSet('titlesEveryone', true)
            ->set('usageOverride', true)->call('saveUsagePolicy');
        $this->assertTrue($usage->titlesEnabledFor($b), 'Replace personal choices: B on too');
    }
}
