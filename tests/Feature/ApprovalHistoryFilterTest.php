<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceEmployee;
use App\Models\SalaryAdjustment;
use App\Models\User;
use App\Services\ApprovalInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The type filter on Approval History also applies to decisions the admin made themself. */
class ApprovalHistoryFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_type_filter_keeps_only_that_type(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $e = AttendanceEmployee::create(['employee_code' => 'AH-1', 'name' => 'History Person', 'is_active' => true, 'joined_at' => '2026-01-01']);
        $correction = AttendanceCorrection::create(['employee_id' => $e->id, 'work_date' => '2026-10-13', 'requested_check_in' => '2026-10-13 10:00:00', 'reason' => 'late entry', 'status' => 'approved']);
        $adjustment = SalaryAdjustment::create(['employee_id' => $e->id, 'kind' => 'earning', 'reason' => 'bonus', 'amount' => 5000, 'effective_on' => '2026-10-16', 'status' => 'approved', 'requested_by' => $admin->id]);
        foreach ([[AttendanceCorrection::class, $correction->id], [SalaryAdjustment::class, $adjustment->id]] as [$type, $id]) {
            ApprovalRequest::create(['approvable_type' => $type, 'approvable_id' => $id, 'approver_id' => $admin->id, 'status' => 'approved', 'decided_at' => now()]);
        }

        $inbox = app(ApprovalInbox::class);
        $this->assertCount(2, $inbox->history($admin));
        $this->assertSame([SalaryAdjustment::class], $inbox->history($admin, SalaryAdjustment::class)->pluck('approvable_type')->unique()->values()->all());

        $this->actingAs($admin)->get(route('approvals.history', ['type' => SalaryAdjustment::class]))->assertOk()
            ->assertSee('History Person · ', false)->assertSee('5,000.00')->assertDontSee('13 Oct 2026');
    }
}
