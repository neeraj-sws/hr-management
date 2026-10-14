<?php

namespace App\Http\Controllers;

use App\Services\ImportService;
use App\Support\XlsxWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Upload of historical salary / attendance records (Excel .xlsx or CSV) and
 * the matching templates. Problems with the file itself (wrong type, too
 * big, missing) are reported in the same result box as row errors, so the
 * person always sees why nothing was imported.
 */
class ImportController extends Controller
{
    public function salary(Request $request, ImportService $import)
    {
        abort_unless($request->user()->canAny(['payroll.manage', 'hr.manage']), 403);
        if ($error = $this->fileError($request)) return back()->with('import_result', ['kind' => 'Salary', 'rows' => 0, 'created' => 0, 'updated' => 0, 'kept' => 0, 'skipped' => 0, 'errors' => [$error], 'notes' => []]);
        $result = $import->salary($request->file('file'), $request->user()->id, $request->boolean('update_existing'));

        return back()->with('import_result', ['kind' => 'Salary', ...$result]);
    }

    public function attendance(Request $request, ImportService $import)
    {
        abort_unless($request->user()->canAny(['hr.manage', 'employees.manage']), 403);
        if ($error = $this->fileError($request)) return back()->with('import_result', ['kind' => 'Attendance', 'rows' => 0, 'created' => 0, 'updated' => 0, 'kept' => 0, 'skipped' => 0, 'errors' => [$error], 'notes' => []]);
        $result = $import->attendance($request->file('file'), $request->user()->id, $request->boolean('update_existing'));

        return back()->with('import_result', ['kind' => 'Attendance', ...$result]);
    }

    private function fileError(Request $request): ?string
    {
        $validator = Validator::make($request->all(), ['file' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv,txt']], [
            'file.required' => 'Choose a file to import.',
            'file.uploaded' => 'The file could not be uploaded (it may be larger than the server allows). Try a smaller file.',
            'file.max' => 'The file is larger than 5 MB. Split it into smaller files.',
            'file.extensions' => 'Upload the Excel template (.xlsx) or a .csv file. Old .xls files: open in Excel and "Save as" .xlsx first.',
        ]);

        return $validator->fails() ? $validator->errors()->first('file') : null;
    }

    /** The Excel template (header row; date/time columns accept Excel dates and times). CSV with ?format=csv. */
    public function template(Request $request, string $type): Response
    {
        [$columns] = ImportService::template($type);
        if ($request->query('format') === 'csv') {
            return response("\xEF\xBB\xBF".implode(',', $columns)."\n", 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$type}-import-template.csv\"",
            ]);
        }

        $path = XlsxWriter::toTempFile($columns, [], ucfirst($type).' import');

        return response()->download($path, "{$type}-import-template.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }
}
