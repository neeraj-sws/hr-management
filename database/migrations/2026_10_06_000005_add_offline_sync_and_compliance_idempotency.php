<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->unsignedInteger('server_version')->default(1);
            $table->char('client_payload_hash',64)->nullable();
            $table->unique(['employee_id','work_date'],'attendance_employee_workday_uq');
        });
        Schema::table('attendance_activity_events', function (Blueprint $table) {
            $table->unique(['attendance_session_id','event_type','event_time'],'attendance_event_idempotency_uq');
        });
        Schema::table('employee_penalties', function (Blueprint $table) {
            $table->string('source_key',150)->nullable()->unique('employee_penalty_source_uq');
        });
        Schema::create('attendance_sync_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_device_id')->constrained('attendance_devices')->cascadeOnDelete();
            $table->string('sync_id',100);
            $table->char('payload_hash',64);
            $table->foreignId('attendance_session_id')->constrained('attendance_sessions')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['attendance_device_id','sync_id'],'attendance_device_sync_id_uq');
            $table->index(['attendance_session_id','created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_sync_receipts');
        Schema::table('employee_penalties',function(Blueprint $table){$table->dropUnique('employee_penalty_source_uq');$table->dropColumn('source_key');});
        Schema::table('attendance_activity_events',function(Blueprint $table){$table->dropUnique('attendance_event_idempotency_uq');});
        Schema::table('attendance_sessions',function(Blueprint $table){$table->dropUnique('attendance_employee_workday_uq');$table->dropColumn(['server_version','client_payload_hash']);});
    }
};
