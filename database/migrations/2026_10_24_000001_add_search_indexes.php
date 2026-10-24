<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Indexes for the header search (names, titles) — skipped where they already exist. */
return new class extends Migration
{
    private const INDEXES = [
        'attendance_employees' => [['name'], ['email'], ['designation']],
        'tasks' => [['title']],
        'projects' => [['name']],
        'employee_notes' => [['title']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $sets) {
            if (! Schema::hasTable($table)) continue;
            foreach ($sets as $columns) {
                $name = $table.'_'.implode('_', $columns).'_search_index';
                if (Schema::hasIndex($table, $name) || ! Schema::hasColumns($table, $columns)) continue;
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $sets) {
            foreach ($sets as $columns) {
                $name = $table.'_'.implode('_', $columns).'_search_index';
                if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }
};
