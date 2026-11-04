<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Help & Support: employee support requests (tickets) with a chat, attachments, an activity history
 * and read markers. Also adds the support permissions and gives them to Master Admin (all three)
 * and HR (support.handle) on existing installs.
 */
return new class extends Migration
{
    public const CATEGORIES = [
        ['Attendance & Check-in/Check-out', 'attendance', false],
        ['Leave & Holidays', 'leave', false],
        ['Salary & Payroll', 'payroll', true],
        ['Tasks & Work', 'tasks', false],
        ['Documents', 'documents', false],
        ['Technical Issues', 'technical', false],
        ['General HR Queries', 'general', false],
        ['Other', 'other', false],
    ];

    public function up(): void
    {
        Schema::create('support_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 40)->unique();
            $table->string('description', 255)->nullable();
            // Restricted: only people with support.payroll see these tickets (e.g. Salary & Payroll).
            $table->boolean('is_restricted')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('attendance_employees')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('category_id')->constrained('support_categories')->restrictOnDelete();
            $table->string('subject', 200);
            $table->text('description');
            $table->string('priority', 10)->default('medium');
            $table->string('status', 30)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->foreignId('last_message_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('reopen_count')->default(0);
            $table->timestamps();
            $table->index(['status', 'updated_at']);
            $table->index(['employee_id', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index(['category_id', 'status']);
            $table->index('created_at');
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // employee | staff
            $table->string('sender_type', 10);
            $table->text('body');
            // Staff-only note: never shown to the employee (website or app).
            $table->boolean('is_internal')->default(false);
            // Moderation by an admin: the text is kept, hidden from the employee, with who / why (also in the audit log).
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hidden_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['ticket_id', 'id']);
        });

        Schema::create('support_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('support_messages')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name', 255);
            $table->string('storage_path', 500);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->timestamps();
            $table->index(['ticket_id', 'message_id']);
        });

        // Activity history: created, status, priority, assignment (who assigned whom, when), category, reopened, message hidden.
        Schema::create('support_ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->string('from_value', 100)->nullable();
            $table->string('to_value', 100)->nullable();
            $table->string('note', 1000)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['ticket_id', 'id']);
            $table->index(['type', 'created_at']);
        });

        // When each person last read a ticket (employee and staff): unread markers and "Seen".
        Schema::create('support_ticket_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_read_at');
            $table->unique(['ticket_id', 'user_id']);
            $table->index('user_id');
        });

        $now = now();
        foreach (self::CATEGORIES as $i => [$name, $slug, $restricted]) {
            DB::table('support_categories')->insert(['name' => $name, 'slug' => $slug, 'is_restricted' => $restricted, 'is_active' => true, 'sort_order' => ($i + 1) * 10, 'created_at' => $now, 'updated_at' => $now]);
        }

        $this->grantPermissions();
    }

    /** support.manage / support.handle / support.payroll: Master Admin gets all three, HR gets support.handle. */
    private function grantPermissions(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) return;
        $now = now();
        $ids = [];
        foreach (['support.manage', 'support.handle', 'support.payroll'] as $name) {
            $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
            $ids[$name] = $id ?: DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }
        $give = function (string $role, array $names) use ($ids) {
            $roleId = DB::table('roles')->where('name', $role)->where('guard_name', 'web')->value('id');
            if (! $roleId) return;
            foreach ($names as $name) {
                $exists = DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $ids[$name])->exists();
                if (! $exists) DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $ids[$name]]);
            }
        };
        $give('Master Admin', ['support.manage', 'support.handle', 'support.payroll']);
        $give('HR', ['support.handle']);
        rescue(fn () => app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions(), null, false);
    }

    public function down(): void
    {
        foreach (['support_ticket_reads', 'support_ticket_events', 'support_attachments', 'support_messages', 'support_tickets', 'support_categories'] as $table) Schema::dropIfExists($table);
        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->whereIn('name', ['support.manage', 'support.handle', 'support.payroll'])->pluck('id');
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
