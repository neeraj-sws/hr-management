<?php

namespace Tests\Feature;

use App\Livewire\People\Employees;
use App\Models\AttendanceEmployee;
use App\Models\User;
use App\Services\HrLetterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** HR and admin can fill an employee's home address (People > Employees > Add / Edit); letters use it. */
class EmployeeAddressByHrTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_adds_the_address_when_adding_and_editing_an_employee(): void
    {
        $this->seed();
        $hr = User::factory()->create(['email' => 'hr-address@company.test']);
        $hr->assignRole('HR');

        Livewire::actingAs($hr)->test(Employees::class)->call('create')
            ->set('employeeCode', 'AD-1')->set('name', 'Asha Dev')->set('email', 'asha@company.test')->set('password', 'Welcome12345A')
            ->set('monthlySalary', '20000')->set('address', "12 MG Road\nIndore 452001")
            ->call('save')->assertHasNoErrors();
        $employee = AttendanceEmployee::where('employee_code', 'AD-1')->firstOrFail();
        $this->assertSame("12 MG Road\nIndore 452001", $employee->address);

        Livewire::actingAs($hr)->test(Employees::class)->call('edit', $employee->id)->assertSet('address', "12 MG Road\nIndore 452001")
            ->set('address', '45 Vijay Nagar, Indore 452010')->call('save')->assertHasNoErrors();
        $this->assertSame('45 Vijay Nagar, Indore 452010', $employee->fresh()->address);
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee.updated']);

        // Letters fill {employee_address} with it.
        $this->assertSame('45 Vijay Nagar, Indore 452010', app(HrLetterService::class)->values($employee->fresh())['employee_address']);

        Livewire::actingAs($hr)->test(Employees::class)->call('edit', $employee->id)->set('address', str_repeat('x', 501))->call('save')->assertHasErrors('address');
    }
}
