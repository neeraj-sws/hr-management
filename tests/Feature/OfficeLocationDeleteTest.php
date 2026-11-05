<?php

namespace Tests\Feature;

use App\Livewire\System\Settings;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\OfficeLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings > Office locations: an office can be deleted; attendance marked there stays (without the office name). */
class OfficeLocationDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_office_can_be_deleted_and_old_attendance_stays(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $old = OfficeLocation::create(['name' => 'Testing office', 'latitude' => 21.8, 'longitude' => 76.3, 'radius_meters' => 200, 'is_active' => true]);
        $main = OfficeLocation::create(['name' => 'Main office', 'latitude' => 22.7, 'longitude' => 75.8, 'radius_meters' => 200, 'is_active' => true]);
        $e = AttendanceEmployee::create(['employee_code' => 'OF-1', 'name' => 'Office Person', 'is_active' => true, 'joined_at' => '2026-01-01']);
        $s = AttendanceSession::create(['employee_id' => $e->id, 'work_date' => '2026-10-30', 'check_in' => '2026-10-30 10:14:00', 'check_out' => '2026-10-30 19:42:00', 'status' => 'completed',
            'check_in_office_id' => $old->id, 'check_in_latitude' => 21.8, 'check_in_longitude' => 76.3]);

        Livewire::actingAs($admin)->test(Settings::class)->set('tab', 'locations')
            ->assertSee('Testing office')->assertSee('1 attendance day(s) were marked here')
            ->call('deleteOffice', $old->id)->assertDontSee('Testing office')->assertSee('Main office');

        $this->assertNull(OfficeLocation::find($old->id));
        $s->refresh();
        $this->assertNull($s->check_in_office_id, 'The day keeps its time and position, without the office name');
        $this->assertSame('10:14', $s->check_in->format('H:i'));
        $this->assertEquals(21.8, (float) $s->check_in_latitude);

        // The last active office cannot go while check-in needs a location.
        Livewire::actingAs($admin)->test(Settings::class)->set('tab', 'locations')->set('requireLocation', true)->call('deleteOffice', $main->id);
        $this->assertNotNull(OfficeLocation::find($main->id));
    }
}
