<?php

namespace Tests\Feature;

use App\Livewire\Account\Profile;
use App\Models\AttendanceEmployee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** The app's Settings screen: own details, address, emergency contact, HR-only details and 2-step sign-in. */
class MobileSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->user = User::factory()->create(['email' => 'ms@company.test']);
        $this->user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'MS-1', 'name' => 'Riya Sharma', 'email' => 'ms@company.test', 'designation' => 'Developer', 'is_active' => true, 'user_id' => $this->user->id, 'joined_at' => '2026-01-01', 'phone' => '98000 11111']);
    }

    public function test_employee_reads_and_saves_their_details_from_the_app(): void
    {
        Sanctum::actingAs($this->user);
        $r = $this->getJson('/api/me/profile')->assertOk()->json('data');
        $this->assertSame('98000 11111', $r['editable']['phone']);
        $this->assertSame('', $r['editable']['address']);
        $this->assertContains(['label' => 'Designation', 'value' => 'Developer'], $r['hr_only']);
        $this->assertFalse($r['two_factor']['on']);

        $this->postJson('/api/me/profile', ['phone' => '+91 99999 00000', 'date_of_birth' => '1998-05-04', 'share_birthday' => false, 'address' => "12 MG Road\nIndore", 'emergency_contact_name' => 'Rakesh Sharma', 'emergency_contact_relation' => 'Father', 'emergency_contact_phone' => '98111 22222'])
            ->assertOk()->assertJsonPath('data.editable.address', "12 MG Road\nIndore");
        $this->employee->refresh();
        $this->assertSame('+91 99999 00000', $this->employee->phone);
        $this->assertSame('1998-05-04', $this->employee->date_of_birth->toDateString());
        $this->assertFalse($this->employee->share_birthday);
        $this->assertSame('Rakesh Sharma', $this->employee->emergency_contact_name);
        $this->assertSame('Father', $this->employee->emergency_contact_relation);
        $this->assertSame('98111 22222', $this->employee->emergency_contact_phone);
        $this->assertDatabaseHas('audit_logs', ['action' => 'profile.contact_updated']);

        // Bad input is refused; HR-only fields are not changed even if sent.
        $this->postJson('/api/me/profile', ['phone' => 'call me', 'date_of_birth' => '2099-01-01'])->assertStatus(422)->assertJsonValidationErrors(['phone', 'date_of_birth']);
        $this->postJson('/api/me/profile', ['phone' => '1', 'name' => 'Hacker', 'monthly_salary' => 999999])->assertOk();
        $this->assertSame('Riya Sharma', $this->employee->fresh()->name);

        // The address is not shared with colleagues through the employee list.
        $this->assertStringNotContainsString('MG Road', $this->getJson('/api/employees/'.$this->employee->id)->getContent());
    }

    public function test_two_factor_switch_in_the_app(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson('/api/me/two-factor', ['on' => false])->assertOk()->assertJsonPath('data.two_factor.on', false);
        $this->assertFalse((bool) $this->user->fresh()->two_factor_enabled);
    }

    public function test_website_profile_saves_address_and_hr_sees_it(): void
    {
        $this->actingAs($this->user);
        Livewire::test(Profile::class)
            ->set('address', 'Flat 4, Vijay Nagar')->set('emergencyName', 'Anita Sharma')->set('emergencyRelation', 'Mother')->set('emergencyPhone', '97000 33333')
            ->call('saveContact')->assertHasNoErrors();
        $this->assertSame('Flat 4, Vijay Nagar', $this->employee->fresh()->address);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($admin)->get(route('people.employees.show', $this->employee))->assertOk()->assertSee('Flat 4, Vijay Nagar')->assertSee('Anita Sharma')->assertSee('97000 33333');
    }
}
