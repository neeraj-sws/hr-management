<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Courses are taught topic by topic, each by a mentor (HTML/CSS by Risha, JavaScript by Fahad or Khushboo, React
 * by Khushboo): a course lists its topics in order with the mentors who can teach each one, and every trainee's
 * enrolment keeps its own topics with the mentor chosen for them and how far they got. A course also gets a
 * proper duration (e.g. 6 months) and its usual number of installments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_courses', function (Blueprint $table) {
            if (! Schema::hasColumn('training_courses', 'duration_value')) $table->unsignedSmallInteger('duration_value')->nullable()->after('duration_label');
            if (! Schema::hasColumn('training_courses', 'duration_unit')) $table->string('duration_unit', 10)->nullable()->after('duration_value');
            if (! Schema::hasColumn('training_courses', 'default_installments')) $table->unsignedTinyInteger('default_installments')->nullable()->after('one_time_discount');
        });

        if (! Schema::hasTable('training_course_topics')) {
            Schema::create('training_course_topics', function (Blueprint $table) {
                $table->id();
                $table->foreignId('training_course_id')->constrained('training_courses')->cascadeOnDelete();
                $table->string('title', 120);
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('training_course_topic_mentors')) {
            Schema::create('training_course_topic_mentors', function (Blueprint $table) {
                $table->foreignId('topic_id')->constrained('training_course_topics')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
                $table->primary(['topic_id', 'employee_id']);
            });
        }

        if (! Schema::hasTable('training_enrollment_topics')) {
            Schema::create('training_enrollment_topics', function (Blueprint $table) {
                $table->id();
                $table->foreignId('enrollment_id')->constrained('training_enrollments')->cascadeOnDelete();
                $table->foreignId('course_topic_id')->nullable()->constrained('training_course_topics')->nullOnDelete();
                $table->string('title', 120);
                $table->unsignedSmallInteger('sort')->default(0);
                $table->foreignId('mentor_id')->nullable()->constrained('attendance_employees')->nullOnDelete();
                $table->string('status', 20)->default('pending');
                $table->date('started_on')->nullable();
                $table->date('completed_on')->nullable();
                $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['mentor_id', 'status']);
            });
        }

        // "4 months" typed earlier becomes 4 + months, so the end date can follow the start date.
        foreach (DB::table('training_courses')->whereNotNull('duration_label')->whereNull('duration_value')->get(['id', 'duration_label']) as $course) {
            if (preg_match('/^\s*(\d{1,3})\s*(day|week|month|year)s?\s*$/i', (string) $course->duration_label, $m)) {
                DB::table('training_courses')->where('id', $course->id)->update(['duration_value' => (int) $m[1], 'duration_unit' => strtolower($m[2]).'s']);
            }
        }

        // A course typed by hand at enrolment ("Web designing") belongs to the listed course of the same name ("Web Designing").
        foreach (DB::table('training_enrollments')->whereNull('training_course_id')->get(['id', 'course_name']) as $enrollment) {
            $course = DB::table('training_courses')->whereRaw('LOWER(TRIM(title)) = ?', [mb_strtolower(trim((string) $enrollment->course_name))])->first(['id', 'title']);
            if ($course) DB::table('training_enrollments')->where('id', $enrollment->id)->update(['training_course_id' => $course->id, 'course_name' => $course->title]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('training_enrollment_topics');
        Schema::dropIfExists('training_course_topic_mentors');
        Schema::dropIfExists('training_course_topics');
        Schema::table('training_courses', function (Blueprint $table) {
            foreach (['duration_value', 'duration_unit', 'default_installments'] as $column) {
                if (Schema::hasColumn('training_courses', $column)) $table->dropColumn($column);
            }
        });
    }
};
