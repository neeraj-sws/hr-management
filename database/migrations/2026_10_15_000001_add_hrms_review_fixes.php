<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HRMS review fixes:
 *  - who checked an employee out on their behalf, why, and when (forced check-out);
 *  - project-wise lines in the daily work status;
 *  - the assignment/return history of each asset (kept when it is returned or re-issued);
 *  - cancelling an offboarding case that was opened by mistake;
 *  - clean-up of notifications that still point at deleted announcements.
 * Additive only: no existing column or row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->foreignId('forced_checkout_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('forced_checkout_reason', 30)->nullable();
            $table->string('forced_checkout_note', 500)->nullable();
            $table->timestamp('forced_checkout_at')->nullable();
        });

        Schema::table('daily_work_statuses', function (Blueprint $table) {
            $table->json('project_updates')->nullable();
        });

        Schema::create('employee_asset_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_asset_id')->constrained('employee_assets')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('attendance_employees')->nullOnDelete();
            $table->string('action', 30);   // assigned | returned | lost | written_off | recovery_requested
            $table->date('happened_on');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'action']);
        });

        Schema::table('employee_lifecycle_cases', function (Blueprint $table) {
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 1000)->nullable();
        });

        // Existing assets get their history started from what is already known.
        foreach (DB::table('employee_assets')->get() as $asset) {
            DB::table('employee_asset_events')->insert(['employee_asset_id' => $asset->id, 'employee_id' => $asset->employee_id, 'action' => 'assigned', 'happened_on' => $asset->assigned_on ?? substr((string) $asset->created_at, 0, 10) ?: now()->toDateString(), 'notes' => null, 'created_at' => now(), 'updated_at' => now()]);
            if ($asset->status !== 'assigned') {
                DB::table('employee_asset_events')->insert(['employee_asset_id' => $asset->id, 'employee_id' => $asset->employee_id, 'action' => $asset->status, 'happened_on' => $asset->returned_on ?? substr((string) $asset->updated_at, 0, 10) ?: now()->toDateString(), 'notes' => null, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        // Notifications for announcements that were deleted before deletion also removed them.
        $existing = DB::table('announcements')->pluck('id')->map(fn ($id) => (int) $id)->all();
        DB::table('notifications')->where('data', 'like', '%"kind":"Announcement"%')->orderBy('created_at')->chunk(500, function ($rows) use ($existing) {
            $stale = collect($rows)->filter(fn ($row) => ! in_array((int) (json_decode($row->data, true)['record_id'] ?? 0), $existing, true))->pluck('id');
            if ($stale->isNotEmpty()) DB::table('notifications')->whereIn('id', $stale)->delete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_lifecycle_cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
        Schema::dropIfExists('employee_asset_events');
        Schema::table('daily_work_statuses', function (Blueprint $table) {
            $table->dropColumn('project_updates');
        });
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('forced_checkout_by');
            $table->dropColumn(['forced_checkout_reason', 'forced_checkout_note', 'forced_checkout_at']);
        });
    }
};
