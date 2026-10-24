<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\User;
use App\Support\PersonBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** One badge set for who someone is, the same in the header, people lists, profiles and attendance. */
class PersonBadgesTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $code, ?string $role, array $extra = []): AttendanceEmployee
    {
        $user = $role ? User::factory()->create(['email' => strtolower($code).'@example.com']) : null;
        $user?->assignRole($role);

        return AttendanceEmployee::create(['employee_code' => $code, 'name' => "Person {$code}", 'email' => $user?->email, 'is_active' => true, 'user_id' => $user?->id, 'joined_at' => '2026-01-01', ...$extra]);
    }

    private function labels(?User $user, ?AttendanceEmployee $employee = null): array
    {
        return array_column(PersonBadges::for($user, $employee), 'label');
    }

    public function test_each_kind_of_person_gets_its_badge(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $employee = $this->person('PB-1', 'Employee');
        $trainee = $this->person('PB-2', 'Employee', ['employment_type' => 'trainee']);
        $hr = $this->person('PB-3', 'HR');
        $manager = $this->person('PB-4', 'Manager');
        $lead = $this->person('PB-5', 'Employee');
        $this->person('PB-6', 'Employee', ['manager_id' => $lead->id]);
        $noLogin = $this->person('PB-7', null);

        $this->assertSame(['Master Admin'], $this->labels($admin));
        $this->assertSame(['Employee'], $this->labels($employee->user));
        $this->assertSame(['Trainee'], $this->labels($trainee->user), 'A trainee is shown as Trainee, not Employee');
        $this->assertSame(['HR'], $this->labels($hr->user));
        $this->assertSame(['Manager'], $this->labels($manager->user));
        $this->assertSame(['Team lead'], $this->labels($lead->user), 'Direct reports without a Manager role: Team lead');
        $this->assertSame(['Employee', 'No login'], $this->labels(null, $noLogin));
        $this->assertSame('Trainee', PersonBadges::primary($trainee->user));

        // Shown in the people list and the header.
        Livewire::actingAs($admin)->test(\App\Livewire\People\Employees::class)->assertSeeText('Team lead')->assertSeeText('Manager');
        $this->actingAs($trainee->user)->get(route('attendance.my'))->assertOk()->assertSeeText('Trainee');
    }

    public function test_the_apps_get_the_badges_and_the_photo_set_on_the_website(): void
    {
        $this->seed();
        \Illuminate\Support\Facades\Storage::fake('local');
        $trainee = $this->person('PB-8', 'Employee', ['employment_type' => 'trainee']);
        \Laravel\Sanctum\Sanctum::actingAs($trainee->user, ['*']);

        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.badges.0.label', 'Trainee')->assertJsonPath('data.photo_url', null);
        $this->get('/api/me/photo')->assertNotFound();

        // A photo set on the website reaches the app; a new photo gets a new address (no stale cache).
        \Illuminate\Support\Facades\Storage::disk('local')->put('employee-photos/pb-8.jpg', 'jpeg-bytes');
        $trainee->update(['photo_path' => 'employee-photos/pb-8.jpg']);
        \Laravel\Sanctum\Sanctum::actingAs($trainee->user->fresh(), ['*']);
        $url = $this->getJson('/api/me')->json('data.photo_url');
        $this->assertStringContainsString('/api/me/photo?v=', $url);
        $this->get('/api/me/photo')->assertOk();
        \Illuminate\Support\Facades\Storage::disk('local')->put('employee-photos/pb-8-new.jpg', 'new-bytes');
        $trainee->update(['photo_path' => 'employee-photos/pb-8-new.jpg']);
        \Laravel\Sanctum\Sanctum::actingAs($trainee->user->fresh(), ['*']);
        $this->assertNotSame($url, $this->getJson('/api/me')->json('data.photo_url'));
    }
}
