<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class ReportExportController extends Controller
{
    public function __invoke(Request $request, string $type, ReportService $reports, AuditLogger $audit): Response
    {
        abort_unless(isset(ReportService::TYPES[$type]), 404);
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'team_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'format' => ['nullable', 'in:csv,xlsx'],
        ]);
        $format = $validated['format'] ?? 'csv';
        $filters = collect($validated)->except('format')->all();
        abort_if(Carbon::parse($filters['from'])->diffInDays(Carbon::parse($filters['to'])) > 366, 422, 'Export date range may not exceed one year.');
        abort_unless($reports->canView($request->user(), $type), 403);

        $columns = $reports->columns($type);
        $rows = $reports->rows($request->user(), $type, $filters);
        $audit->record('report.exported', null, ['type' => $type, 'format' => $format, 'filters' => $filters, 'rows' => $rows->count()]);
        $filename = "{$type}-report-{$filters['from']}-{$filters['to']}";

        if ($format === 'xlsx') {
            $cells = $rows->map(fn ($row) => array_map(fn ($column) => $this->safeCell($row[$column] ?? null), $columns));
            $path = \App\Support\XlsxWriter::toTempFile($columns, $cells, $reports->label($type));

            return response()->download($path, "{$filename}.xlsx", [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns);
            foreach ($rows as $row) fputcsv($out, array_map(fn ($column) => $this->safeCell($row[$column] ?? null), $columns));
            fclose($out);
        }, "{$filename}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralises spreadsheet formula injection. */
    private function safeCell(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^\s*[=+\-@]/u', $value) ? "'".$value : $value;
    }
}
