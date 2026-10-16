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
        // The old month-wise salary sheet (Name, Basic salary … one sheet per month) goes to Legacy Salary.
        if ($this->isOldSalarySheet($request->file('file'))) {
            $result = app(\App\Services\LegacySalaryImport::class)->import($request->file('file'), $request->user()->id, $request->boolean('update_existing'));
            array_unshift($result['notes'], 'This is the old month-wise salary sheet, so it was imported into Payroll → Legacy Salary (salary profiles were not changed).');
            $result['legacy_link'] = true;

            return back()->with('import_result', ['kind' => 'Salary', ...$result]);
        }
        $result = $import->salary($request->file('file'), $request->user()->id, $request->boolean('update_existing'));

        return back()->with('import_result', ['kind' => 'Salary', ...$result]);
    }

    public function attendance(Request $request, ImportService $import)
    {
        abort_unless($request->user()->canAny(['hr.manage', 'employees.manage']), 403);
        if ($error = $this->fileError($request)) return back()->with('import_result', ['kind' => 'Attendance', 'rows' => 0, 'created' => 0, 'updated' => 0, 'kept' => 0, 'skipped' => 0, 'errors' => [$error], 'notes' => []]);
        $intoLocked = $request->boolean('into_locked') && \App\Support\Access::isAdmin($request->user());
        $result = $import->attendance($request->file('file'), $request->user()->id, $request->boolean('update_existing'), $request->integer('employee_id') ?: null, $intoLocked, $request->integer('leave_type_id') ?: null);
        // A one-person sheet whose Employee ID is unknown: keep the file for a short while, so the person
        // only has to pick the employee in the result box (no second upload).
        if (count($result['unknown_codes'] ?? []) === 1 && ! $request->integer('employee_id')) {
            $disk = \Illuminate\Support\Facades\Storage::disk('local');
            foreach ($disk->files('import-pending') as $old) if ($disk->lastModified($old) < now()->subDay()->getTimestamp()) $disk->delete($old); // never picked up
            $token = (string) \Illuminate\Support\Str::uuid();
            $request->file('file')->storeAs('import-pending', $token.'.'.strtolower($request->file('file')->getClientOriginalExtension() ?: 'csv'), 'local');
            $result['pending'] = ['token' => $token, 'name' => $request->file('file')->getClientOriginalName(), 'code' => $result['unknown_codes'][0], 'update_existing' => $request->boolean('update_existing'), 'into_locked' => $intoLocked, 'leave_type_id' => $request->integer('leave_type_id') ?: null];
        }

        return $this->afterAttendanceImport($result);
    }

    /** Second step of the above: the kept file is imported for the chosen employee, then deleted. */
    public function attendanceFor(Request $request, ImportService $import)
    {
        abort_unless($request->user()->canAny(['hr.manage', 'employees.manage']), 403);
        $data = $request->validate(['token' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:255'], 'employee_id' => ['required', 'exists:attendance_employees,id']], ['employee_id.required' => 'Choose the employee these rows belong to.']);
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $path = collect($disk->files('import-pending'))->first(fn ($f) => str_starts_with(basename($f), $data['token'].'.'));
        if (! $path) return back()->with('import_result', ['kind' => 'Attendance', 'rows' => 0, 'created' => 0, 'updated' => 0, 'kept' => 0, 'skipped' => 0, 'errors' => ['The uploaded file is no longer available. Choose the file again and pick the employee before importing.'], 'notes' => []]);
        $file = new \Illuminate\Http\UploadedFile($disk->path($path), $data['name'], null, null, true);
        $result = $import->attendance($file, $request->user()->id, $request->boolean('update_existing'), (int) $data['employee_id'], $request->boolean('into_locked') && \App\Support\Access::isAdmin($request->user()), $request->integer('leave_type_id') ?: null);
        $disk->delete($path);

        return $this->afterAttendanceImport($result);
    }

    /**
     * After an import that added days, open Attendance records filtered to the imported employee(s) and dates,
     * so the imported rows are on screen straight away; otherwise stay on the page with the result.
     */
    private function afterAttendanceImport(array $result)
    {
        $imported = $result['imported'] ?? null;
        if (! $imported) return back()->with('import_result', ['kind' => 'Attendance', ...$result]);
        $filters = ['from' => $imported['from'], 'to' => $imported['to']];
        if (count($imported['employee_ids']) === 1) $filters['employee'] = $imported['employee_ids'][0];

        return redirect()->route('attendance.records', $filters)->with('import_result', ['kind' => 'Attendance', ...$result]);
    }

    /** Old month-wise salary sheet, stored as-is (see LegacySalaryImport). */
    public function legacySalary(Request $request, \App\Services\LegacySalaryImport $import)
    {
        abort_unless($request->user()->canAny(['payroll.manage', 'hr.manage']), 403);
        if ($error = $this->fileError($request)) return back()->with('import_result', ['kind' => 'Legacy salary', 'rows' => 0, 'created' => 0, 'updated' => 0, 'kept' => 0, 'skipped' => 0, 'errors' => [$error], 'notes' => []]);
        $result = $import->import($request->file('file'), $request->user()->id, $request->boolean('update_existing'));

        return back()->with('import_result', ['kind' => 'Legacy salary', ...$result]);
    }

    /** True for the old salary workbook: a header with "Name" and "Basic salary" but no employee_code / monthly_salary columns. */
    private function isOldSalarySheet(\Illuminate\Http\UploadedFile $file): bool
    {
        try {
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $rows = $extension === 'xlsx' ? (\App\Support\XlsxReader::sheets($file->getRealPath())[0]['rows'] ?? []) : [1 => str_getcsv((string) fgets(fopen($file->getRealPath(), 'r')))];
        } catch (\Throwable) {
            return false;
        }
        foreach (array_slice($rows, 0, 10) as $cells) {
            $slugs = array_map(fn ($c) => \App\Services\LegacySalaryImport::slug((string) $c), $cells);
            if (in_array('name', $slugs, true) && in_array('basic_salary', $slugs, true) && ! in_array('monthly_salary', $slugs, true) && ! in_array('employee_code', $slugs, true)) return true;
        }

        return false;
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
        // Attendance (13-column sheet) and legacy salary come with sample rows, formulas, formats and validation.
        if (in_array($type, ['attendance', 'legacy-salary'], true)) {
            if ($type === 'legacy-salary') abort_unless($request->user()->canAny(['payroll.manage', 'hr.manage']), 403);
            if ($request->query('format') === 'csv') {
                return response($type === 'attendance' ? ImportService::attendanceSampleCsv() : \App\Services\LegacySalaryImport::sampleCsv(), 200, [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => "attachment; filename=\"{$type}-import-sample.csv\"",
                ]);
            }
            $path = \App\Support\XlsxWorkbook::toTempFile($type === 'attendance' ? ImportService::attendanceTemplateSheets() : \App\Services\LegacySalaryImport::templateSheets());

            return response()->download($path, "{$type}-import-template.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
        }
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
