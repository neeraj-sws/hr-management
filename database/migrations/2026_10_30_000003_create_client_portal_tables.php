<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client portal: clients (with their own logins), project timesheets they can review, objections on
 * entries, and the month a client approves (which can then be invoiced).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clients')) {
            Schema::create('clients', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('contact_name')->nullable();
                $table->string('email')->nullable();
                $table->string('phone', 40)->nullable();
                $table->foreignId('billing_customer_id')->nullable()->constrained('billing_customers')->nullOnDelete();
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasColumn('users', 'client_id')) {
            Schema::table('users', fn (Blueprint $t) => $t->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete());
        }
        Schema::table('projects', function (Blueprint $t) {
            if (! Schema::hasColumn('projects', 'client_id')) $t->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            if (! Schema::hasColumn('projects', 'hourly_rate')) $t->decimal('hourly_rate', 12, 2)->nullable();
            if (! Schema::hasColumn('projects', 'client_sees_developer')) $t->boolean('client_sees_developer')->default(true);
        });

        if (! Schema::hasTable('timesheet_entries')) {
            Schema::create('timesheet_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
                $table->foreignId('employee_id')->nullable()->constrained('attendance_employees')->nullOnDelete();
                $table->string('developer_name')->nullable(); // when imported for someone not in HRMS
                $table->date('work_date');
                $table->time('start_time')->nullable();
                $table->time('end_time')->nullable();
                $table->unsignedInteger('minutes')->default(0);          // actually worked
                $table->unsignedInteger('billable_minutes')->default(0); // charged to the client
                $table->text('description');
                $table->string('status', 40)->nullable();
                $table->text('remarks')->nullable();
                $table->string('source', 20)->default('manual'); // timer | manual | import
                $table->foreignId('task_time_entry_id')->nullable()->unique()->constrained('task_time_entries')->nullOnDelete();
                $table->string('import_key', 64)->nullable()->unique();
                $table->boolean('visible_to_client')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['project_id', 'work_date']);
            });
        }
        if (! Schema::hasTable('timesheet_objections')) {
            Schema::create('timesheet_objections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('timesheet_entry_id')->constrained('timesheet_entries')->cascadeOnDelete();
                $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('reason');
                $table->unsignedInteger('proposed_minutes')->nullable(); // what the client thinks it should be
                $table->string('status', 20)->default('open'); // open | accepted | rejected
                $table->text('response')->nullable();
                $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('responded_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('timesheet_months')) {
            Schema::create('timesheet_months', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->char('month', 7); // YYYY-MM
                $table->integer('opening_remaining_minutes')->default(0); // "Old remaining" carried in
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('invoice_id')->nullable()->constrained('billing_invoices')->nullOnDelete();
                $table->timestamps();
                $table->unique(['project_id', 'month']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_months');
        Schema::dropIfExists('timesheet_objections');
        Schema::dropIfExists('timesheet_entries');
        Schema::table('projects', function (Blueprint $t) {
            if (Schema::hasColumn('projects', 'client_id')) $t->dropConstrainedForeignId('client_id');
            foreach (['hourly_rate', 'client_sees_developer'] as $c) if (Schema::hasColumn('projects', $c)) $t->dropColumn($c);
        });
        if (Schema::hasColumn('users', 'client_id')) Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('client_id'));
        Schema::dropIfExists('clients');
    }
};
