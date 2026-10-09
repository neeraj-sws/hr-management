<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('attendance_devices', function (Blueprint $table) {
            $table->unsignedBigInteger('personal_access_token_id')->nullable();
            $table->unique('personal_access_token_id', 'att_devices_token_uq');
            $table->foreign('personal_access_token_id', 'att_devices_token_fk')
                ->references('id')
                ->on('personal_access_tokens')
                ->nullOnDelete();
        });

        Schema::table('attendance_activity_events', function (Blueprint $table) {
            $table->unique(
                ['attendance_session_id', 'event_type', 'event_time'],
                'att_events_idempotency_uq'
            );
        });
    }

    public function down(): void
    {
        Schema::table('attendance_activity_events', function (Blueprint $table) {
            $table->dropUnique('att_events_idempotency_uq');
        });

        Schema::table('attendance_devices', function (Blueprint $table) {
            $table->dropForeign('att_devices_token_fk');
            $table->dropUnique('att_devices_token_uq');
            $table->dropColumn('personal_access_token_id');
        });
    }
};
