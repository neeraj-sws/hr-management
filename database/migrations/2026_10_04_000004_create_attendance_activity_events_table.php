<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attendance_activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_session_id')
                ->constrained('attendance_sessions')
                ->cascadeOnDelete();

            $table->string('event_type');
            $table->dateTime('event_time');
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['attendance_session_id', 'event_time'], 'att_events_session_time_idx');
            $table->index(['event_type', 'event_time'], 'att_events_type_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_activity_events');
    }
};
