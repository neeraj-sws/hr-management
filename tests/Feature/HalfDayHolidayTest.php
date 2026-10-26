<?php

namespace Tests\Feature;

use App\Livewire\System\Settings;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\HrWorkflowService;
use App\Services\PeopleOperationsService;
use App\Support\WorkCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** Half-day festival holiday: office closes early; coming in = full day, not coming = ½ day (paid holiday). */
class HalfDayHolidayTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-26 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create(['email' => 'festival@example.com']);
        $user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'FH-1', 'name' => 'Farah Festival', 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-01-01', 'daily_work_hours' => 8, 'work_start' => '10:00', 'work_end' => '18:30']);
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 22000, 'effective_from' => '2026-01-01', 'working_days_per_month' => 22, 'standard_daily_hours' => 8]);
        $this->calendar(true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Tuesday 20 Oct 2026: Diwali eve, office closes at 2 PM. */
    private function calendar(bool $paid): void
    {
        app(PeopleOperationsService::class)->setPolicy('calendar.working_days', 'calendar', 'Working calendar', [
            'days' => [1, 2, 3, 4, 5], 'holidays' => ['2026-10-20'], 'holiday_names' => ['2026-10-20' => 'Diwali eve'],
            'holiday_types' => ['2026-10-20' => ['category' => 'festival', 'paid' => $paid, 'closes_at' => '14:00']],
        ], null, $this->admin->id, '2026-01-01');
    }

    private function day(string $date, string $in, string $out): void
    {
        AttendanceSession::create(['employee_id' => $this->employee->id, 'work_date' => $date, 'check_in' => "{$date} {$in}:00", 'check_out' => "{$date} {$out}:00", 'active_seconds' => Carbon::parse("{$date} {$in}")->diffInSeconds(Carbon::parse("{$date} {$out}")), 'status' => 'completed']);
    }

    private function base(): float
    {
        return (float) app(HrWorkflowService::class)->calculatePayroll('2026-10-01', '2026-10-31', $this->admin->id)->items()->where('employee_id', $this->employee->id)->firstOrFail()->calculation_breakdown['base_salary'];
    }

    public function test_the_day_stays_a_working_day_that_ends_at_closing_time(): void
    {
        $attendance = app(AttendanceService::class);
        $this->assertNotNull($attendance->halfDayHoliday($this->employee, '2026-10-20'));
        $this->assertSame('2026-10-20 14:00', $attendance->expectedEnd($this->employee, '2026-10-20')->format('Y-m-d H:i'), 'Reminders and the desktop check-out use 2 PM');
        $this->assertSame('2026-10-21 18:30', $attendance->expectedEnd($this->employee, '2026-10-21')->format('Y-m-d H:i'), 'Other days unchanged');
        $day = $attendance->days($this->employee, Carbon::parse('2026-10-20'), Carbon::parse('2026-10-20'))->first();
        $this->assertSame('absent', $day['status']);
        $this->assertStringContainsString('Half-day holiday (Diwali eve) · closes 2:00 PM', $day['label']);
    }

    public function test_coming_in_is_a_full_day_and_not_coming_is_half_a_day(): void
    {
        $this->day('2026-10-12', '10:00', '18:30');
        $this->day('2026-10-20', '10:00', '13:30'); // 3 h 30 min on Diwali eve: still a full day
        $this->assertFalse(app(AttendanceService::class)->isHalfDay(AttendanceSession::whereDate('work_date', '2026-10-20')->first()));
        $this->assertEqualsWithDelta(2000, $this->base(), 0.01);

        AttendanceSession::whereDate('work_date', '2026-10-20')->delete();
        $this->assertEqualsWithDelta(1500, $this->base(), 0.01, 'Not at work on the half-day holiday: ½ day paid');
        $month = app(\App\Services\SalarySummaryService::class)->soFar($this->employee);
        $this->assertStringContainsString('½ day for 1 half-day holiday not at work (20 Oct)', $month['earnings'][0][2]);
    }

    public function test_an_unpaid_half_day_holiday_pays_nothing_when_not_at_work(): void
    {
        $this->calendar(false);
        $this->day('2026-10-12', '10:00', '18:30');
        $this->assertEqualsWithDelta(1000, $this->base(), 0.01);
    }

    public function test_settings_add_a_half_day_holiday_and_employees_see_it(): void
    {
        Livewire::actingAs($this->admin)->test(Settings::class)
            ->set('newHoliday', '2026-11-13')->set('newHolidayName', 'Bhai Dooj')->set('newHolidayCategory', 'festival')->set('newHolidayClosesAt', '13:00')
            ->call('addHoliday')->call('saveCalendar')->assertHasNoErrors();
        $calendar = app(\App\Services\PolicyConfigurationService::class)->forEmployee(null, 'calendar.working_days', '2026-12-31', []);
        $this->assertSame('13:00', WorkCalendar::holidayTypeOf($calendar, '2026-11-13')['closes_at']);
        $this->assertTrue(WorkCalendar::isWorkday('2026-11-13', $calendar), 'The office is open in the morning');

        Sanctum::actingAs($this->employee->user);
        $names = collect($this->getJson('/api/holidays?year=2026')->assertOk()->json('data'))->pluck('name');
        $this->assertContains('Bhai Dooj · half day, office closes 1:00 PM', $names->all());
        $this->actingAs($this->employee->user)->get(route('company.holidays'))->assertOk()->assertSee('Half day · closes 1:00 PM');
    }
}
