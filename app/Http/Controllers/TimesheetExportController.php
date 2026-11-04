<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\TimesheetService;
use App\Support\XlsxWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Download a project's monthly timesheet as a native .xlsx (same columns as the table). */
class TimesheetExportController extends Controller
{
    public function __invoke(Request $request, TimesheetService $timesheets)
    {
        abort_unless($request->user()?->canAny(['projects.manage', 'hr.manage']), 403);

        $project = Project::with('client')->findOrFail((int) $request->query('project'));
        $month = $request->query('month', today()->format('Y-m'));
        if (! preg_match('/^\d{4}-\d{2}$/', (string) $month)) $month = today()->format('Y-m');
        $monthLabel = Carbon::createFromFormat('Y-m', $month)->format('M Y');

        $headers = ['Date', 'Developer', 'Task', 'Start', 'End', 'Worked (min)', 'Billable (min)', 'Status', 'Remarks'];
        $rows = $timesheets->entries($project, $month)->map(fn ($e) => [
            $e->work_date->format('d M Y'),
            $e->employee?->name ?? $e->developer_name ?? '',
            (string) $e->description,
            $e->start_time ? substr($e->start_time, 0, 5) : '',
            $e->end_time ? substr($e->end_time, 0, 5) : '',
            (int) $e->minutes,
            (int) $e->billable_minutes,
            (string) $e->status,
            (string) $e->remarks,
        ])->all();

        $sheetName = preg_replace('/[^A-Za-z0-9 ]/', '', $monthLabel) ?: 'Timesheet';
        $path = XlsxWriter::toTempFile($headers, $rows, $sheetName);
        $file = preg_replace('/[^A-Za-z0-9]+/', '-', trim($project->name.' '.$monthLabel)).'.xlsx';

        return response()->download($path, $file, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }
}
