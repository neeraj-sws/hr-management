<?php

namespace Tests\Feature;

use App\Livewire\Attendance\Records;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveConflictService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Attendance records: opens on today, the cards follow the chosen dates / employee, one employee gets their whole
 * range at a glance, and rows are coloured like the calendar. A half-day leave with work is never a leave conflict.
 */
class AttendanceRecordsFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $risha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-30 15:00:00'); // Friday
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create(['name' => 'Risha Gangrade']);
        $user->assignRole('Employee');
        $this->risha = AttendanceEmployee::create(['employee_code' => 'EMP-01', 'name' => 'Risha Gangrade', 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '18:30:00', 'daily_work_hours' => 8.5]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function day(string $date, string $in, ?string $out): void
    {
        AttendanceSession::create(['employee_id' => $this->risha->id, 'work_date' => $date, 'check_in' => "{$date} {$in}:00", 'check_out' => $out ? "{$date} {$out}:00" : null, 'status' => $out ? 'completed' : 'working']);
    }

    public function test_opens_on_today_and_the_cards_follow_the_dates(): void
    {
        $this->day('2026-10-29', '10:30', '19:00'); // yesterday, late
        $this->day('2026-10-30', '10:00', null);    // today, working

        Livewire::actingAs($this->admin)->test(Records::class)
            ->assertSet('from', '2026-10-30')->assertSet('to', '2026-10-30')
            ->assertSee('Present Today')->assertSee('Working Now')
            ->set('from', '2026-10-29')->set('to', '2026-10-29')->call('search')
            ->assertSee('Present · yesterday')->assertDontSee('Working Now')->assertSee('Absent')
            ->assertViewHas('summary', fn ($s) => $s['people'] === 1 && $s['late_people'] === 1)
            ->call('resetFilters')->assertSet('from', '2026-10-30');
    }

    public function test_one_employee_gets_their_range_at_a_glance_and_coloured_rows(): void
    {
        $this->day('2026-10-26', '10:00', '19:00');           // present
        $this->day('2026-10-27', '10:40', '19:10');           // late
        $this->day('2026-10-28', '10:00', '13:00');           // half day
        // 29 Oct: nothing, with a leave waiting for approval
        $type = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        LeaveRequest::create(['employee_id' => $this->risha->id, 'leave_type_id' => $type->id, 'starts_on' => '2026-10-29', 'ends_on' => '2026-10-29', 'days' => 1, 'duration' => 'full', 'reason' => 'Fever', 'status' => 'pending']);
        $this->day('2026-10-25', '11:00', '17:00');           // Sunday: extra working

        Livewire::actingAs($this->admin)->test(Records::class)->set('from', '2026-10-24')->set('to', '2026-10-29')->set('employee', $this->risha->id)->call('search')
            ->assertViewHas('person', fn ($p) => $p['present'] === 4 && $p['late'] === 1 && $p['half_day'] === 1 && $p['leave_pending'] === 1 && $p['absent'] === 0 && $p['extra'] === 1)
            ->assertSee('Leave pending')->assertSee('without approval yet')->assertSee('Extra working')
            ->assertSeeHtml('class="day-row rec-weekend"')->assertSeeHtml('class="rec-extra_work"')->assertSeeHtml('class="rec-half_day"')->assertSeeHtml('class="day-row rec-leave_pending"');
    }

    public function test_half_day_leave_with_work_is_not_a_conflict(): void
    {
        $type = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        $this->day('2026-10-28', '14:00', '18:30');
        LeaveRequest::create(['employee_id' => $this->risha->id, 'leave_type_id' => $type->id, 'starts_on' => '2026-10-28', 'ends_on' => '2026-10-28', 'days' => 0.5, 'duration' => 'half', 'reason' => 'Doctor', 'status' => 'approved']);
        $this->assertCount(0, app(LeaveConflictService::class)->open('2026-10-01', '2026-10-31'));

        // A full-day leave with work still is.
        $this->day('2026-10-27', '10:00', '18:30');
        LeaveRequest::create(['employee_id' => $this->risha->id, 'leave_type_id' => $type->id, 'starts_on' => '2026-10-27', 'ends_on' => '2026-10-27', 'days' => 1, 'duration' => 'full', 'reason' => 'Trip', 'status' => 'approved']);
        $this->assertCount(1, app(LeaveConflictService::class)->open('2026-10-01', '2026-10-31'));
    }
}
