<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time fix: some employees' current salary record still said 8 h/day while the employee record
 * (used by attendance and payroll) said 8.5. The current salary record now carries the employee's hours,
 * as every later change already does. Older salary records are left as they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('attendance_employees')->where('daily_work_hours', '>', 0)->get(['id', 'daily_work_hours']) as $employee) {
            $latest = DB::table('salary_profiles')->where('employee_id', $employee->id)->orderByDesc('effective_from')->orderByDesc('id')->first(['id', 'standard_daily_hours']);
            if ($latest && round((float) $latest->standard_daily_hours, 2) !== round((float) $employee->daily_work_hours, 2)) {
                DB::table('salary_profiles')->where('id', $latest->id)->update(['standard_daily_hours' => $employee->daily_work_hours, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Data fix only; nothing to undo.
    }
};
