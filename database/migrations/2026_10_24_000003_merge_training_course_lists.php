<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One course list for Training > Courses and Course fees: a course can carry a fee, a duration and a
 * discount for paying at once. Enrolments point to the course; courses that only existed under Course fees
 * are copied into the list (the old table stays, unused).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_courses', function (Blueprint $table) {
            if (! Schema::hasColumn('training_courses', 'fee')) $table->decimal('fee', 12, 2)->nullable()->after('duration_hours');
            if (! Schema::hasColumn('training_courses', 'one_time_discount')) $table->decimal('one_time_discount', 12, 2)->default(0)->after('fee');
            if (! Schema::hasColumn('training_courses', 'duration_label')) $table->string('duration_label', 60)->nullable()->after('one_time_discount');
        });
        Schema::table('training_enrollments', function (Blueprint $table) {
            if (! Schema::hasColumn('training_enrollments', 'training_course_id')) $table->foreignId('training_course_id')->nullable()->after('fee_course_id')->constrained('training_courses')->nullOnDelete();
        });

        if (! Schema::hasTable('training_fee_courses')) return;
        foreach (DB::table('training_fee_courses')->get() as $old) {
            $id = DB::table('training_courses')->whereRaw('LOWER(title) = ?', [mb_strtolower($old->name)])->value('id')
                ?? DB::table('training_courses')->insertGetId(['title' => $old->name, 'description' => $old->description, 'category' => 'Course', 'fee' => $old->fee, 'duration_label' => $old->duration,
                    'is_mandatory' => false, 'is_active' => $old->is_active, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('training_courses')->where('id', $id)->whereNull('fee')->update(['fee' => $old->fee, 'duration_label' => $old->duration]);
            DB::table('training_enrollments')->where('fee_course_id', $old->id)->whereNull('training_course_id')->update(['training_course_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('training_enrollments', fn (Blueprint $t) => $t->dropConstrainedForeignId('training_course_id'));
        Schema::table('training_courses', fn (Blueprint $t) => $t->dropColumn(['fee', 'one_time_discount', 'duration_label']));
    }
};
