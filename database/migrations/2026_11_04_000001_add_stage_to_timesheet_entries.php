<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Timesheet lines get a separate Stage (Local / React / Live) next to the Status (Done / In progress / Not done). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('timesheet_entries', 'stage')) {
            Schema::table('timesheet_entries', fn (Blueprint $table) => $table->string('stage', 40)->nullable()->after('status'));
        }
        // Lines saved with a stage in the Status field move it to the new column.
        DB::table('timesheet_entries')->whereIn('status', ['Local', 'React', 'Live'])->update(['stage' => DB::raw('status'), 'status' => null]);
        // Old sheets wrote "InProgress" / "NotDone".
        DB::table('timesheet_entries')->where('status', 'InProgress')->update(['status' => 'In progress']);
        DB::table('timesheet_entries')->where('status', 'NotDone')->update(['status' => 'Not done']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('timesheet_entries', 'stage')) {
            DB::table('timesheet_entries')->whereNull('status')->whereNotNull('stage')->update(['status' => DB::raw('stage')]);
            Schema::table('timesheet_entries', fn (Blueprint $table) => $table->dropColumn('stage'));
        }
    }
};
