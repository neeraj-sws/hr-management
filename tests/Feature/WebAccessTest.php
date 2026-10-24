<?php

namespace Tests\Feature;

use App\Livewire\System\Settings;
use App\Models\AttendanceEmployee;
use App\Models\User;
use App\Services\WebAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings > Security > Website access: employees use the apps; selected roles and people use the website. */
class WebAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function person(string $code, string $role): User
    {
        $user = User::factory()->create(['email' => strtolower($code).'@example.com', 'password' => 'Password12345', 'is_active' => true]);
        $user->assignRole($role);
        AttendanceEmployee::create(['employee_code' => $code, 'name' => "Person {$code}", 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01']);

        return $user;
    }

    private function onlyRoles(array $roles, array $userIds = []): void
    {
        Livewire::actingAs($this->admin)->test(Settings::class)->set('tab', 'security')->assertSee('Website access')
            ->set('webAccessMode', 'roles')->set('webAccessRoles', $roles)->set('webAccessUserIds', array_map('strval', $userIds))
            ->call('saveWebAccess')->assertHasNoErrors();
        auth()->guard('web')->logout();
    }

    public function test_everyone_can_use_the_website_until_it_is_limited(): void
    {
        $employee = $this->person('WA-1', 'Employee');
        $this->assertSame('all', app(WebAccessService::class)->settings()['mode']);
        $this->post('/login', ['email' => $employee->email, 'password' => 'Password12345'])->assertRedirect(route('dashboard'));
    }

    public function test_employees_use_the_apps_hr_and_chosen_people_use_the_website(): void
    {
        $employee = $this->person('WA-2', 'Employee');
        $hr = $this->person('WA-3', 'HR');
        $chosen = $this->person('WA-4', 'Employee');
        $this->onlyRoles(['HR'], [$chosen->id]);

        // An employee is refused at sign-in with the reason (no code e-mailed, no session).
        $this->post('/login', ['email' => $employee->email, 'password' => 'Password12345'])->assertSessionHasErrors(['email' => WebAccessService::MESSAGE]);
        $this->assertGuest('web');
        // HR, a person chosen by name and the Master Admin (always) can sign in.
        $this->post('/login', ['email' => $hr->email, 'password' => 'Password12345'])->assertRedirect(route('dashboard'));
        $this->post('/logout');
        $this->post('/login', ['email' => $chosen->email, 'password' => 'Password12345'])->assertRedirect(route('dashboard'));
        $this->post('/logout');
        $this->assertTrue(app(WebAccessService::class)->allows($this->admin));

        // The desktop / mobile app still signs the employee in.
        $this->postJson('/api/auth/token', ['email' => $employee->email, 'password' => 'Password12345', 'device_name' => 'phone'])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_someone_already_signed_in_is_signed_out_on_the_next_click(): void
    {
        $employee = $this->person('WA-5', 'Employee');
        $this->actingAs($employee)->get(route('attendance.my'))->assertOk();
        $this->onlyRoles(['HR']);

        $this->actingAs($employee)->get(route('attendance.my'))->assertRedirect(route('login'))->assertSessionHasErrors(['email' => WebAccessService::MESSAGE]);
        $this->assertGuest('web');
    }
}
