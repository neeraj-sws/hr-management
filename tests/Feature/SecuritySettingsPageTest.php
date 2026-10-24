<?php

namespace Tests\Feature;

use App\Livewire\System\Settings;
use App\Models\User;
use App\Services\WebAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings > Security: status cards, option cards, role chips, people picker, unsaved bar, cancel. */
class SecuritySettingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_choose_roles_and_people_then_save_or_cancel(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $abheeshri = User::factory()->create(['name' => 'Abheeshri Parmar']);
        $abheeshri->assignRole('Employee');

        $page = Livewire::actingAs($admin)->test(Settings::class)->set('tab', 'security')
            ->assertSee('Website access')->assertSee('All changes saved')
            ->set('webAccessMode', 'roles')->assertSee('Unsaved changes')
            ->call('toggleWebRole', 'HR')->assertSet('webAccessRoles', ['HR'])
            ->call('toggleWebRole', 'Master Admin')->assertSet('webAccessRoles', ['HR'], 'Master Admin is always allowed, not a choice')
            ->set('webPeopleSearch', 'abhee')->assertSee('Abheeshri Parmar')
            ->call('addWebUser', $abheeshri->id)->assertSet('webPeopleSearch', '');
        $page->call('saveWebAccess')->assertHasNoErrors()->assertSee('All changes saved');
        $this->assertTrue(app(WebAccessService::class)->allows($abheeshri->fresh()));

        $page->call('removeWebUser', $abheeshri->id)->assertSee('Unsaved changes')->call('resetWebAccess')
            ->assertSet('webAccessUserIds', [(string) $abheeshri->id])->assertSee('Changed by SWS Admin');
        $page->set('twoFactorMode', 'admins')->assertSee('Also for the desktop and mobile apps')->call('resetSecurity')->assertSet('twoFactorMode', 'off');
    }
}
