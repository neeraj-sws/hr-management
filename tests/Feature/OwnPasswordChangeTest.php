<?php

namespace Tests\Feature;

use App\Livewire\Account\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** Admins and HR change their own password on My profile; employees still go through HR. */
class OwnPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_and_hr_change_their_own_password(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $admin->forceFill(['password' => Hash::make('OldPassword123')])->save();
        $admin->createToken('mobile');

        Livewire::actingAs($admin)->test(Profile::class)->assertSee('Change password')
            ->set('currentPassword', 'wrong')->set('newPassword', 'NewPassword456')->set('newPassword_confirmation', 'NewPassword456')
            ->call('changePassword')->assertHasErrors('currentPassword')
            ->set('currentPassword', 'OldPassword123')->set('newPassword', 'short1')->set('newPassword_confirmation', 'short1')
            ->call('changePassword')->assertHasErrors('newPassword')
            ->set('newPassword', 'NewPassword456')->set('newPassword_confirmation', 'NewPassword789')
            ->call('changePassword')->assertHasErrors('newPassword')
            ->set('newPassword_confirmation', 'NewPassword456')
            ->call('changePassword')->assertHasNoErrors();
        $this->assertTrue(Hash::check('NewPassword456', $admin->fresh()->password));
        $this->assertSame(0, $admin->tokens()->count(), 'The apps sign in again');
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_changed_self']);

        $hr = User::factory()->create(['password' => Hash::make('HrPassword1234')]);
        $hr->assignRole('HR');
        Livewire::actingAs($hr)->test(Profile::class)->set('currentPassword', 'HrPassword1234')->set('newPassword', 'HrNewPassword99')->set('newPassword_confirmation', 'HrNewPassword99')
            ->call('changePassword')->assertHasNoErrors();
        $this->assertTrue(Hash::check('HrNewPassword99', $hr->fresh()->password));
    }

    public function test_employees_still_ask_hr(): void
    {
        $user = User::factory()->create(['password' => Hash::make('EmpPassword1234')]);
        $user->assignRole('Employee');
        Livewire::actingAs($user)->test(Profile::class)->assertDontSee('Change password')->assertSee('only an administrator can change your password')
            ->set('currentPassword', 'EmpPassword1234')->set('newPassword', 'EmpNewPassword99')->set('newPassword_confirmation', 'EmpNewPassword99')
            ->call('changePassword')->assertForbidden();
    }
}
