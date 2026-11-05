<?php

namespace Tests\Feature;

use App\Livewire\Company\Announcements;
use App\Models\Announcement;
use App\Models\AttendanceEmployee;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** Announcements sent to chosen employees: only the people picked get and see it (website and app). */
class AnnouncementChosenEmployeesTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $code): AttendanceEmployee
    {
        $user = User::factory()->create(['email' => strtolower($code).'@example.com']);
        $user->assignRole('Employee');

        return AttendanceEmployee::create(['employee_code' => $code, 'name' => 'Person '.$code, 'email' => $user->email, 'user_id' => $user->id, 'is_active' => true, 'joined_at' => '2026-01-01']);
    }

    public function test_only_the_chosen_employees_get_and_see_it(): void
    {
        $this->seed();
        Notification::fake();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        [$one, $two, $other] = [$this->person('ANN-1'), $this->person('ANN-2'), $this->person('ANN-3')];

        // Nobody chosen: not saved.
        Livewire::actingAs($admin)->test(Announcements::class)->call('edit')
            ->set('title', 'Your review')->set('body', 'Please meet HR at 4 PM.')->set('audience', 'employees')
            ->assertSee('Person ANN-3')->call('save')->assertHasErrors(['employeeIds' => 'required_if']);

        Livewire::actingAs($admin)->test(Announcements::class)->call('edit')
            ->set('title', 'Your review')->set('body', 'Please meet HR at 4 PM.')->set('audience', 'employees')
            ->set('employeeIds', [(string) $one->id, (string) $two->id])->call('save')->assertHasNoErrors();

        $announcement = Announcement::where('title', 'Your review')->sole();
        $this->assertSame('employees', $announcement->audience);
        $this->assertSame([$one->id, $two->id], $announcement->employee_ids);
        $this->assertSame('Employees: Person ANN-1, Person ANN-2', $announcement->audienceLabel());

        Notification::assertSentTo($one->user, ApprovalRequested::class);
        Notification::assertSentTo($two->user, ApprovalRequested::class);
        Notification::assertNotSentTo($other->user, ApprovalRequested::class);

        // Website list.
        Livewire::actingAs($one->user)->test(Announcements::class)->assertSee('Your review');
        Livewire::actingAs($other->user)->test(Announcements::class)->assertDontSee('Your review');

        // Mobile app list.
        Sanctum::actingAs($two->user);
        $this->getJson('/api/announcements')->assertOk()->assertJsonFragment(['title' => 'Your review']);
        Sanctum::actingAs($other->user);
        $this->getJson('/api/announcements')->assertOk()->assertJsonMissing(['title' => 'Your review']);
    }

    public function test_a_single_employee_can_be_chosen_and_editing_keeps_the_choice(): void
    {
        $this->seed();
        Notification::fake();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $one = $this->person('ANN-9');

        Livewire::actingAs($admin)->test(Announcements::class)->call('edit')
            ->set('title', 'Laptop ready')->set('body', 'Collect it from IT.')->set('audience', 'employees')
            ->set('employeeIds', [(string) $one->id])->call('save')->assertHasNoErrors();
        $announcement = Announcement::where('title', 'Laptop ready')->sole();
        Notification::assertSentTo($one->user, ApprovalRequested::class);

        Livewire::actingAs($admin)->test(Announcements::class)->call('edit', $announcement->id)
            ->assertSet('audience', 'employees')->assertSet('employeeIds', [(string) $one->id])
            ->set('audience', 'all')->call('save')->assertHasNoErrors();
        $this->assertNull($announcement->fresh()->employee_ids);
    }
}
