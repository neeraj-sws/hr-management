<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_employees', function (Blueprint $table) {
            // Filled by the employee (website My profile or the app's Settings); HR sees it on the profile.
            if (! Schema::hasColumn('attendance_employees', 'address')) $table->text('address')->nullable();
            if (! Schema::hasColumn('attendance_employees', 'emergency_contact_name')) $table->string('emergency_contact_name', 120)->nullable();
            if (! Schema::hasColumn('attendance_employees', 'emergency_contact_relation')) $table->string('emergency_contact_relation', 60)->nullable();
            if (! Schema::hasColumn('attendance_employees', 'emergency_contact_phone')) $table->string('emergency_contact_phone', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_employees', function (Blueprint $table) {
            $table->dropColumn(['address', 'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone']);
        });
    }
};
