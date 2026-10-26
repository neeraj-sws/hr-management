<?php

namespace Tests\Feature;

use App\Livewire\Monitoring\Screenshots;
use App\Models\AttendanceEmployee;
use App\Models\Screenshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Screenshots: never your own; a manager sees the team; a viewer opens a screenshot large. */
class ScreenshotsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_sees_the_team_not_themself_and_employees_have_no_menu_item(): void
    {
        $this->seed();
        Storage::fake('local');
        $mgrUser = User::factory()->create(['name' => 'Mona Manager']);
        $mgrUser->assignRole('Manager');
        $boss = AttendanceEmployee::create(['employee_code' => 'SP-M', 'name' => 'Mona Manager', 'is_active' => true, 'user_id' => $mgrUser->id, 'joined_at' => '2026-01-01']);
        $empUser = User::factory()->create(['name' => 'Ravi Report']);
        $empUser->assignRole('Employee');
        $emp = AttendanceEmployee::create(['employee_code' => 'SP-1', 'name' => 'Ravi Report', 'is_active' => true, 'user_id' => $empUser->id, 'manager_id' => $boss->id, 'joined_at' => '2026-01-01']);
        foreach ([$boss, $emp] as $person) {
            Storage::disk('local')->put("screenshots/{$person->id}.jpg", 'img');
            Screenshot::create(['employee_id' => $person->id, 'captured_at' => now(), 'path' => "screenshots/{$person->id}.jpg", 'bytes' => 3]);
        }

        Livewire::actingAs($mgrUser)->test(Screenshots::class)->assertSet('employee', $emp->id)->assertSee('Ravi Report')->assertSee('View')
            ->set('employee', $boss->id)->assertDontSee('Download');
        $this->actingAs($mgrUser)->get(route('monitoring.screenshot', Screenshot::where('employee_id', $boss->id)->sole()))->assertForbidden();

        $this->actingAs($empUser)->get(route('dashboard'))->assertDontSee('href="'.route('monitoring.screenshots').'"', false);
        Livewire::actingAs($empUser)->test(Screenshots::class)->assertSee('No one to show');
    }
}
