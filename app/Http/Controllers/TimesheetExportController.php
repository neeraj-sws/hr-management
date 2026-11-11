<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\TimesheetService;
use App\Support\Duration;
use App\Support\XlsxWorkbook;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Download a project's monthly timesheet as .xlsx in the same layout as the
 * company's client sheet: Date, Task Details, Start/End Time, Hours, In Minutes,
 * Billable, Developer, Status, Stage, Remarks — an "Old remaining" line on top and the
 * totals (worked, billable, hours, rate, amount) underneath.
 */
class TimesheetExportController extends Controller
{
    public function __invoke(Request $request, TimesheetService $timesheets)
    {
        abort_unless($request->user()?->canAny(['projects.manage', 'hr.manage']), 403);

        $project = Project::with('client')->findOrFail((int) $request->query('project'));
        $month = (string) $request->query('month', today()->format('Y-m'));
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) $month = today()->format('Y-m');
        $monthLabel = Carbon::createFromFormat('Y-m', $month)->format('M Y');
        $summary = $timesheets->summary($project, $month);
        $entries = $timesheets->entries($project, $month);

        $columns = [
            ['Date', 'date', 12], ['Task Details', 'wrap', 60], ['Start Time', 'time', 10], ['End Time', 'time', 10],
            ['Hours', 'text', 10], ['In Minutes', 'integer', 11], ['Billable (min)', 'integer', 13],
            ['Developer', 'text', 18], ['Status', 'text', 11], ['Stage', 'text', 9], ['Remarks', 'wrap', 30], ['Client sees', 'text', 11],
        ];
        $rows = [];
        if ($summary['opening_minutes']) {
            $rows[] = ['', 'Old remaining (carried in)', '', '', Duration::words($summary['opening_minutes']), '', $summary['opening_minutes'], '', '', '', '', ''];
        }
        foreach ($entries as $e) {
            $rows[] = [
                $e->work_date->toDateString(),
                (string) $e->description,
                $e->start_time ? substr($e->start_time, 0, 5) : '',
                $e->end_time ? substr($e->end_time, 0, 5) : '',
                Duration::words((int) $e->minutes),
                (int) $e->minutes,
                (int) $e->billable_minutes,
                $e->developer() === '—' ? '' : $e->developer(),
                (string) $e->status,
                (string) $e->stage,
                (string) $e->remarks,
                $e->visible_to_client ? 'Yes' : 'No',
            ];
        }
        // Totals as plain numbers (not formulas) so every viewer — phone previews included — shows them.
        $worked = (int) $entries->sum('minutes');
        $billable = (int) $summary['billable_minutes']; // includes the carried-in minutes
        $rows[] = array_fill(0, 12, '');
        $rows[] = ['', ['v' => 'Total', 's' => 'title'], '', '', Duration::words($billable), ['v' => $worked, 's' => 'integer'], ['v' => $billable, 's' => 'integer'], '', '', '', '', ''];
        $rows[] = ['', ['v' => 'Total hours', 's' => 'title'], '', '', '', ['v' => round($worked / 60, 2), 's' => 'currency'], ['v' => round($billable / 60, 2), 's' => 'currency'], '', '', '', '', ''];
        if ($summary['rate']) {
            $rows[] = ['', ['v' => 'Rate per hour (₹)', 's' => 'title'], '', '', '', '', ['v' => (float) $summary['rate'], 's' => 'currency'], '', '', '', '', ''];
            $rows[] = ['', ['v' => 'Amount (₹)', 's' => 'title'], '', '', '', '', ['v' => (float) $summary['amount'], 's' => 'currency'], '', '', '', '', ''];
        }
        if ($summary['approved_at']) {
            $rows[] = ['', 'Approved by '.$summary['approved_by'].' on '.$summary['approved_at']->format('d M Y'), '', '', '', '', '', '', '', '', '', ''];
        }
        if ($summary['invoice']) {
            $rows[] = ['', 'Invoice '.$summary['invoice']->number(), '', '', '', '', '', '', '', '', '', ''];
        }

        $path = XlsxWorkbook::toTempFile([['name' => $monthLabel, 'columns' => $columns, 'rows' => $rows]]);
        $file = preg_replace('/[^A-Za-z0-9]+/', '-', trim($project->name.' '.$monthLabel)).'.xlsx';

        return response()->download($path, $file, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
