<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Others" leave type: the employee writes what kind of leave it is (e.g. "Exam"), kept on the request and shown
 * as "Others: Exam". Unpaid unless HR makes it paid in Settings > Leave types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('is_other')->default(false)->after('is_active');
        });
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('other_type', 100)->nullable()->after('leave_type_id');
        });
        // Existing installs: a type already called "Others" becomes this one; otherwise it is added after the others.
        // A new install gets it from the seeder (after its normal leave types).
        if (! DB::table('leave_types')->exists()) return;
        if (DB::table('leave_types')->where('name', 'Others')->exists()) {
            DB::table('leave_types')->where('name', 'Others')->update(['is_other' => true, 'is_active' => true]);
        } else {
            DB::table('leave_types')->insert(['name' => 'Others', 'annual_allowance' => 0, 'carry_forward' => false, 'paid' => false, 'is_active' => true, 'is_other' => true, 'monthly_accrual' => 0, 'allow_advance' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('leave_types')->where('is_other', true)->where('name', 'Others')->whereNotExists(fn ($q) => $q->from('leave_requests')->whereColumn('leave_requests.leave_type_id', 'leave_types.id'))->delete();
        Schema::table('leave_requests', fn (Blueprint $table) => $table->dropColumn('other_type'));
        Schema::table('leave_types', fn (Blueprint $table) => $table->dropColumn('is_other'));
    }
};
