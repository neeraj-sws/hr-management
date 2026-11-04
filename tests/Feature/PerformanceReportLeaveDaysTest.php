<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Performance report: "Approved leave days" counts only the days inside the chosen dates; a longer leave is noted. */
class PerformanceReportLeaveDaysTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_leave_days_inside_the_dates_are_counted(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-11-04 12:00:00');
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $e = AttendanceEmployee::create(['employee_code' => 'VC-1', 'name' => 'Vijay Long Leave', 'is_active' => true, 'joined_at' => '2026-01-01']);
        $type = LeaveType::where('name', 'Annual Leave')->firstOrFail();
        // A 74-day leave from 15 Oct, and a half day inside the dates.
        LeaveRequest::create(['employee_id' => $e->id, 'leave_type_id' => $type->id, 'starts_on' => '2026-10-15', 'ends_on' => '2026-12-27', 'days' => 74, 'reason' => 'Long leave', 'status' => 'approved', 'duration' => 'full']);
        $other = AttendanceEmployee::create(['employee_code' => 'HD-1', 'name' => 'Half Day Person', 'is_active' => true, 'joined_at' => '2026-01-01']);
        LeaveRequest::create(['employee_id' => $other->id, 'leave_type_id' => $type->id, 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-03', 'days' => 0.5, 'reason' => 'Doctor', 'status' => 'approved', 'duration' => 'half']);

        $rows = app(ReportService::class)->rows($admin, 'performance', ['from' => '2026-11-01', 'to' => '2026-11-04'])->keyBy('employee_code');

        // 01-04 Nov: Sunday 1 Nov is off, so 2, 3, 4 Nov = 3 days (default Mon-Fri calendar).
        $this->assertEquals(3, $rows['VC-1']['approved_leave_days']);
        $this->assertSame('3 of a 74-day leave (15 Oct – 27 Dec 2026)', $rows['VC-1']['approved_leave_note']);
        $this->assertEquals(0.5, $rows['HD-1']['approved_leave_days']);
        $this->assertNull($rows['HD-1']['approved_leave_note']);
    }
}
