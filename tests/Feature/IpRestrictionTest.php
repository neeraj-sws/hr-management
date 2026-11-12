<?php

namespace Tests\Feature;

use App\Livewire\System\Settings;
use App\Models\User;
use App\Services\IpRestrictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings > Security > IP restriction: the website opens only from office IPs; Master Admin and the apps are not affected. */
class IpRestrictionTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_outside_the_office_ip_are_signed_out_but_master_admin_is_not(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        Livewire::actingAs($admin)->test(Settings::class)->set('tab', 'security')
            ->set('ipRestrictEnabled', true)->set('ipRestrictList', "not-an-ip")->call('saveIpRestriction')->assertHasErrors('ipRestrictList')
            ->set('ipRestrictList', "103.21.4.0/24\n10.0.0.5")->call('saveIpRestriction')->assertHasNoErrors();
        $this->assertSame(['enabled' => true, 'ips' => ['103.21.4.0/24', '10.0.0.5']], app(IpRestrictionService::class)->settings());

        // From the office range: allowed. From elsewhere: signed out with the reason.
        $this->actingAs($employee)->withServerVariables(['REMOTE_ADDR' => '103.21.4.77'])->get(route('dashboard'))->assertOk();
        $this->actingAs($employee)->withServerVariables(['REMOTE_ADDR' => '49.36.1.2'])->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest('web');

        // Master Admin is never locked out.
        $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => '49.36.1.2'])->get(route('dashboard'))->assertOk();

        // Turned off: everyone again.
        Livewire::actingAs($admin)->test(Settings::class)->set('tab', 'security')->set('ipRestrictEnabled', false)->call('saveIpRestriction')->assertHasNoErrors();
        $this->actingAs($employee)->withServerVariables(['REMOTE_ADDR' => '49.36.1.2'])->get(route('dashboard'))->assertOk();
    }
}
