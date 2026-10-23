<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Goals (KRA / OKR) with weight and progress; self-review and 360 feedback in the review cycle. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->string('kind', 10)->default('kra'); // kra | okr
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('target', 200)->nullable(); // how success is measured, e.g. "20 new leads a month"
            $table->unsignedTinyInteger('weight')->default(0); // % of the review; an employee's goals for a period add up to 100
            $table->unsignedTinyInteger('progress')->default(0); // 0-100
            $table->string('status', 20)->default('active'); // proposed | active | completed | cancelled
            $table->date('starts_on');
            $table->date('ends_on');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'ends_on']);
        });

        Schema::create('performance_goal_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained('performance_goals')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('progress');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating')->nullable()->change(); // set when the manager completes the review
            $table->unsignedTinyInteger('self_rating')->nullable()->after('rating');
            $table->text('self_assessment')->nullable()->after('self_rating');
            $table->timestamp('self_submitted_at')->nullable()->after('self_assessment');
            $table->json('goal_scores')->nullable()->after('metrics'); // goal id => manager score 1-5
            $table->timestamp('completed_at')->nullable()->after('goal_scores');
        });

        Schema::create('performance_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('performance_reviews')->cascadeOnDelete();
            $table->foreignId('giver_id')->constrained('users')->cascadeOnDelete();
            $table->string('relationship', 20)->default('peer'); // peer | report | manager | other
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->string('status', 20)->default('requested'); // requested | submitted | declined | closed
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['review_id', 'giver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_feedback');
        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->dropColumn(['self_rating', 'self_assessment', 'self_submitted_at', 'goal_scores', 'completed_at']);
        });
        Schema::dropIfExists('performance_goal_updates');
        Schema::dropIfExists('performance_goals');
    }
};
