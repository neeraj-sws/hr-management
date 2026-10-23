<?php

namespace Tests\Feature;

use App\Livewire\People\Employees;
use App\Livewire\People\Trainees;
use App\Models\AttendanceEmployee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Trainees have their own list under People; the Employees list leaves them out. */
class TraineeListTest extends TestCase
{
    use RefreshDatabase;

    public function test_trainees_are_listed_apart_from_employees(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        AttendanceEmployee::create(['employee_code' => 'EMP-91', 'name' => 'Ravi Regular', 'is_active' => true, 'employment_type' => 'full_time', 'joined_at' => '2026-01-01']);
        AttendanceEmployee::create(['employee_code' => 'TR-01', 'name' => 'Tina Trainee', 'is_active' => true, 'employment_type' => 'trainee', 'joined_at' => '2026-09-01']);

        Livewire::actingAs($admin)->test(Employees::class)->assertSee('Ravi Regular')->assertDontSee('Tina Trainee')->assertSee('1 trainee is listed under Trainees');
        Livewire::actingAs($admin)->test(Trainees::class)->assertSee('Tina Trainee')->assertDontSee('Ravi Regular')->assertSee('Add trainee')
            ->call('create')->assertSet('employmentType', 'trainee');
        $this->actingAs($admin)->get(route('people.trainees'))->assertOk()->assertSee('Trainees');
        $this->actingAs($admin)->get(route('dashboard'))->assertSee(route('people.trainees'), false);
    }
}
