<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Effective-dated team membership, so past reports and team policies use the team a person was in on that day. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_team_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['team_id', 'starts_on']);
            $table->index(['employee_id', 'ends_on']);
        });

        // Current memberships start on the day they were added (or the joining date when older).
        foreach (DB::table('employee_team')->join('attendance_employees', 'attendance_employees.id', '=', 'employee_team.employee_id')->get(['employee_team.employee_id', 'employee_team.team_id', 'employee_team.created_at', 'attendance_employees.joined_at']) as $row) {
            DB::table('employee_team_history')->insert(['employee_id' => $row->employee_id, 'team_id' => $row->team_id, 'starts_on' => substr((string) ($row->joined_at ?? $row->created_at ?? now()), 0, 10), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_team_history');
    }
};
