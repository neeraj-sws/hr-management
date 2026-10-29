<?php

namespace Tests\Feature;

use App\Livewire\Reports\Report;
use App\Models\AttendanceEmployee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** A heading click sorts the whole report, not only the 25 rows on screen: newest first puts 26–28 Oct on page 1. */
class ReportSortAllPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sorting_by_work_date_covers_every_page(): void
    {
        $this->seed();
        Carbon::setTestNow('2026-10-28 20:00:00');
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $employee = AttendanceEmployee::create(['employee_code' => 'EMP-SORT', 'name' => 'Sort Person', 'is_active' => true, 'joined_at' => '2026-01-01', 'work_start' => '10:00:00', 'work_end' => '18:00:00', 'daily_work_hours' => 8]);

        $page = fn ($component) => collect($component->viewData('rows')->items())->pluck('work_date')->all();
        $report = Livewire::actingAs($admin)->withQueryParams(['month' => '2026-10', 'employee' => $employee->id])->test(Report::class, ['type' => 'attendance']);
        $all = app(\App\Services\ReportService::class)->rows($admin, 'attendance', ['from' => '2026-10-01', 'to' => '2026-10-28', 'employee_id' => $employee->id])->pluck('work_date')->sort()->values()->all();
        $this->assertGreaterThan(25, count($all), 'More than one page');

        $report->call('sortBy', 'work_date')->call('sortBy', 'work_date')->assertSet('dir', 'desc');
        $first = $page($report);
        $this->assertSame(array_slice(array_reverse($all), 0, 25), $first, 'Newest days are on page 1');
        $this->assertSame('2026-10-26', $first[1] ?? null);

        $report->call('sortBy', 'work_date')->assertSet('dir', 'asc');
        $this->assertSame('2026-10-01', $page($report)[0]);
        $report->call('sortBy', 'not_a_column')->assertSet('sort', 'work_date');
    }
}
