<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\LegacySalaryRecord;
use App\Support\XlsxReader;
use App\Support\XlsxWorkbook;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Imports the old salary workbook ("Salary Sheet 2026-2027.xlsx"): one sheet per
 * month named like "April-2026", columns A–AM (S. No. … Comments). A single
 * sheet / CSV with a "Payroll Month" column works too. Every value is stored
 * exactly as in the sheet — formula results unrounded, nothing recalculated —
 * so the paid history stays as it was. People are matched by Employee ID
 * (optional column) or by name; only HRMS employees are imported (other rows are listed, not saved).
 * Free-text lines written under a row ("Khushboo mam", "3000 paid 12 Oct") are
 * kept as notes on that person's row.
 */
class LegacySalaryImport
{
    /**
     * key => [column, header as in the sheet, type, formula in the sheet (row 2), meaning].
     * Types: text, number (count / minutes), days, amount, date.
     */
    public const COLUMNS = [
        's_no' => ['A', 'S. No.', 'number', null, 'Serial number'],
        'name' => ['B', 'Name', 'text', null, 'Employee name — used to match the HRMS employee when there is no Employee ID'],
        'basic_salary' => ['C', 'Basic salary', 'amount', null, 'Monthly basic salary'],
        'salary_per_day' => ['D', 'Salary par day (C/F)', 'amount', '=C{r}/F{r}', 'Basic salary ÷ total working days'],
        'total_monthly_days' => ['E', 'Total monthly days', 'days', null, 'Days in the month'],
        'total_working_days' => ['F', 'Total working days', 'days', null, 'Working days in the month'],
        'per_hour_salary' => ['G', 'Per hour salary (D/9)', 'amount', '=D{r}/9', 'Salary per day ÷ 9 hours'],
        'per_minute_salary' => ['H', 'Per minute salary (G/60)', 'amount', '=G{r}/60', 'Per hour salary ÷ 60'],
        'total_lunch_time' => ['I', 'Total lunch time given (45 * J)', 'number', '=45*J{r}', '45 minutes × present days'],
        'present_days' => ['J', 'Present days ', 'days', null, 'Days present'],
        'leave_days' => ['K', 'Leave days (F-J)', 'days', '=F{r}-J{r}', 'Working days − present days'],
        'paid_leave' => ['L', 'Paid leave', 'days', null, 'Paid leave days'],
        'third_saturday_sunday_work' => ['M', 'Oficial 3rd saturday and Sunday work ', 'days', null, 'Official 3rd Saturday / Sunday worked'],
        'saturday_bonus' => ['N', 'Saturday salary bonus (L*H)', 'amount', '=L{r}*H{r}', 'As in the sheet: L × H'],
        'deduction_reason' => ['O', 'Deduction payment reason', 'text', null, 'Free text'],
        'late_coming_minutes' => ['P', 'Late coming minutes', 'number', null, 'Late minutes in the month'],
        'adjusted_late_coming_minutes' => ['Q', 'Adjusted late coming min', 'number', null, 'Late minutes forgiven / adjusted'],
        'hourly_leave_minutes' => ['R', 'Hourly leave minutes', 'number', null, 'Minutes of hourly leave'],
        'late_and_hourly_leave_minutes' => ['S', 'Late Coming and hourly leave plus P+R', 'number', '=P{r}+R{r}', 'Late minutes + hourly leave minutes'],
        'late_coming_deduction' => ['T', 'Late coming deduction (S*H) ', 'amount', '=S{r}*H{r}', 'Late + hourly leave minutes × per minute salary'],
        'late_coming_adjusted' => ['U', 'late coming adjusted (Q*H)', 'amount', '=Q{r}*H{r}', 'Adjusted minutes × per minute salary (given back)'],
        'without_application_leave' => ['V', 'Without application leave', 'days', null, 'Leave days without application'],
        'without_application_short_leave' => ['W', 'Without application SHORT leave', 'days', null, 'Short leaves without application'],
        'short_leave_minutes' => ['X', 'short leave in min (less than 8:30 hrs)', 'number', null, 'Minutes short of an 8:30 day'],
        'short_leave_deduction' => ['Y', 'Without app Short leave deduction (X*1.5)', 'amount', '=X{r}*1.5', 'Short minutes × 1.5'],
        'task_email_missing' => ['Z', 'Task email missing', 'number', null, 'Days the task e-mail was missing'],
        'without_application_deduction' => ['AA', 'Without application 1 day deduction (V*D/2)', 'amount', '=V{r}*D{r}/2', 'Leave without application × salary per day ÷ 2'],
        'total_payment_deduction' => ['AB', 'Total payment deduction (Y+AA+T-U)', 'amount', '=Y{r}+AA{r}+T{r}-U{r}', 'Short leave + without-application + late deductions − adjusted'],
        'total_salary_paid_days' => ['AC', 'Total salary paid days (J+L)', 'days', '=J{r}+L{r}', 'Present days + paid leave'],
        'salary_before_adjustment' => ['AD', 'Salary before adjustment (AC*D)', 'amount', '=AC{r}*D{r}', 'Paid days × salary per day'],
        'increment_bonus' => ['AE', 'Increment/Bonus amount', 'amount', null, 'Increment or bonus'],
        'tds' => ['AF', 'TDS', 'amount', null, 'Tax deducted at source'],
        'salary_after_deduction' => ['AG', 'Salary after deduction (AD-AB- AF)', 'amount', '=AD{r}-AB{r}-AF{r}', 'Salary before adjustment − deductions − TDS'],
        'total_salary_with_bonus' => ['AH', 'Total salary included bonus (AF+AE)', 'amount', '=AE{r}+AG{r}', 'Salary after deduction + bonus (the sheet computes AE+AG)'],
        'salary_paid' => ['AI', 'Salary paid', 'amount', null, 'Amount actually paid'],
        'salary_remains' => ['AJ', 'salary remains(AH-AI)', 'amount', '=AH{r}-AI{r}', 'Total salary incl. bonus − salary paid'],
        'payment_date' => ['AK', 'Payment date', 'date', null, 'Date paid (Excel date or YYYY-MM-DD)'],
        'payment_status' => ['AL', 'Status', 'text', null, 'Paid / Partially Paid / Unpaid'],
        'comments' => ['AM', 'Comments', 'text', null, 'Free text'],
        'employee_code' => ['AN', 'Employee ID', 'text', null, 'Optional: HRMS employee code — the surest match (otherwise the name is used)'],
        'payroll_month' => ['AO', 'Payroll Month', 'month', null, 'Only when the sheet is not named by month: YYYY-MM (e.g. 2026-04)'],
    ];

    /** Other spellings of the headers (after removing anything in brackets), incl. the 24-column list. */
    private const ALIASES = [
        'sno' => 's_no', 's_no' => 's_no', 'sr_no' => 's_no', 'serial_no' => 's_no',
        'name' => 'name', 'employee_name' => 'name',
        'basic_salary' => 'basic_salary', 'basic' => 'basic_salary',
        'salary_par_day' => 'salary_per_day', 'salary_per_day' => 'salary_per_day',
        'total_monthly_days' => 'total_monthly_days', 'total_working_days' => 'total_working_days',
        'per_hour_salary' => 'per_hour_salary', 'per_minute_salary' => 'per_minute_salary',
        'total_lunch_time_given' => 'total_lunch_time', 'total_lunch_time' => 'total_lunch_time',
        'present_days' => 'present_days', 'leave_days' => 'leave_days', 'paid_leave' => 'paid_leave',
        'oficial_3rd_saturday_and_sunday_work' => 'third_saturday_sunday_work', 'official_3rd_saturday_and_sunday_work' => 'third_saturday_sunday_work',
        'saturday_salary_bonus' => 'saturday_bonus',
        'deduction_payment_reason' => 'deduction_reason', 'deduction_reason' => 'deduction_reason',
        'late_coming_minutes' => 'late_coming_minutes', 'late_coming_min' => 'late_coming_minutes',
        'adjusted_late_coming_min' => 'adjusted_late_coming_minutes', 'adjusted_late_coming_minutes' => 'adjusted_late_coming_minutes',
        'hourly_leave_minutes' => 'hourly_leave_minutes',
        'late_coming_and_hourly_leave_plus_p_r' => 'late_and_hourly_leave_minutes', 'late_coming_and_hourly_leave' => 'late_and_hourly_leave_minutes',
        'late_coming_deduction' => 'late_coming_deduction', 'late_coming_adjusted' => 'late_coming_adjusted',
        'without_application_leave' => 'without_application_leave',
        'without_application_short_leave' => 'without_application_short_leave', 'without_application_short' => 'without_application_short_leave',
        'short_leave_in_min' => 'short_leave_minutes', 'short_leave_in_min_less_than_8_30' => 'short_leave_minutes', 'short_leave_minutes' => 'short_leave_minutes',
        'without_app_short_leave_deduction' => 'short_leave_deduction', 'short_leave_deduction' => 'short_leave_deduction',
        'task_email_missing' => 'task_email_missing',
        'without_application_1_day_deduction' => 'without_application_deduction', 'without_application_1_day' => 'without_application_deduction',
        'total_payment_deduction' => 'total_payment_deduction', 'total_salary_paid_days' => 'total_salary_paid_days',
        'salary_before_adjustment' => 'salary_before_adjustment',
        'increment_bonus_amount' => 'increment_bonus', 'increment_bonus' => 'increment_bonus', 'tds' => 'tds',
        'salary_after_deduction' => 'salary_after_deduction',
        'total_salary_included_bonus' => 'total_salary_with_bonus',
        'salary_paid' => 'salary_paid', 'salary_remains' => 'salary_remains',
        'payment_date' => 'payment_date', 'status' => 'payment_status', 'payment_status' => 'payment_status', 'comments' => 'comments', 'comment' => 'comments', 'remarks' => 'comments',
        'employee_id' => 'employee_code', 'emp_id' => 'employee_code', 'employee_code' => 'employee_code', 'emp_code' => 'employee_code',
        'payroll_month' => 'payroll_month', 'month' => 'payroll_month', 'salary_month' => 'payroll_month',
    ];

    /** Sample input values (Khushboo, Vijay, Risha — April 2026 of the old sheet); the formula columns are filled in by Excel. */
    public const SAMPLE = [
        ['s_no' => 1, 'name' => 'Khushboo Verma', 'basic_salary' => 35000, 'total_monthly_days' => 30, 'total_working_days' => 25, 'present_days' => 14, 'paid_leave' => 0, 'late_coming_minutes' => 751, 'adjusted_late_coming_minutes' => 0, 'hourly_leave_minutes' => 0, 'without_application_leave' => 0, 'without_application_short_leave' => 0, 'short_leave_minutes' => 0, 'task_email_missing' => 0, 'increment_bonus' => 0, 'tds' => 0, 'salary_paid' => 13591.48, 'payment_date' => '2026-05-08', 'payment_status' => 'Partially Paid'],
        ['s_no' => 2, 'name' => 'Vijay Choudhary', 'basic_salary' => 21000, 'total_monthly_days' => 30, 'total_working_days' => 25, 'present_days' => 21, 'paid_leave' => 0, 'late_coming_minutes' => 162, 'adjusted_late_coming_minutes' => 0, 'hourly_leave_minutes' => 0, 'without_application_leave' => 0, 'without_application_short_leave' => 0, 'short_leave_minutes' => 0, 'task_email_missing' => 0, 'increment_bonus' => 0, 'tds' => 0, 'salary_paid' => 13169.44, 'payment_date' => '2026-05-08', 'payment_status' => 'Partially Paid'],
        ['s_no' => 3, 'name' => 'Risha Gangrade', 'basic_salary' => 15000, 'total_monthly_days' => 30, 'total_working_days' => 25, 'present_days' => 23, 'paid_leave' => 0, 'late_coming_minutes' => 0, 'adjusted_late_coming_minutes' => 0, 'hourly_leave_minutes' => 0, 'without_application_leave' => 0, 'without_application_short_leave' => 0, 'short_leave_minutes' => 0, 'task_email_missing' => 0, 'increment_bonus' => 0, 'tds' => 0, 'salary_paid' => 9000, 'payment_date' => '2026-05-08', 'payment_status' => 'Partially Paid'],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function import(UploadedFile $file, int $actorId, bool $updateExisting = false): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        try {
            $sheets = $extension === 'xlsx' ? XlsxReader::sheets($file->getRealPath()) : [['name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 'rows' => $this->csvRows($file->getRealPath())]];
        } catch (\Throwable $e) {
            return $this->summary(errors: [$e->getMessage()]);
        }

        $created = $updated = $kept = $read = $linked = 0;
        $errors = $notes = [];
        $months = [];
        $matchedBy = ['code' => 0, 'name' => 0, 'none' => []];
        foreach ($sheets as $sheet) {
            [$header, $headerRow] = $this->header($sheet['rows']);
            if (! $header) { if ($sheet['rows']) $notes[] = "Sheet \"{$sheet['name']}\": no header row with a \"Name\" column — skipped."; continue; }
            $sheetMonth = self::monthFromSheetName($sheet['name']);
            if (! $sheetMonth && ! in_array('payroll_month', $header, true)) { $errors[] = "Sheet \"{$sheet['name']}\": the month is unknown. Name the sheet like \"April-2026\" or add a \"Payroll Month\" column (YYYY-MM)."; continue; }
            $unknown = array_values(array_filter($header, fn ($h) => is_string($h) && str_starts_with($h, '?')));
            if ($unknown) $notes[] = "Sheet \"{$sheet['name']}\": column(s) ".implode(', ', array_map(fn ($h) => '"'.substr($h, 1).'"', $unknown)).' are not known — kept only in the stored original row.';

            // Employee rows, then the free-text lines under them become notes.
            $people = [];
            $sheetNotes = [];
            foreach ($sheet['rows'] as $rowNumber => $cells) {
                if ($rowNumber <= $headerRow) continue;
                $row = [];
                foreach ($header as $index => $key) if ($key && ! str_starts_with($key, '?') && isset($cells[$index])) $row[$key] = $cells[$index];
                if (trim((string) ($row['name'] ?? '')) !== '' && $this->looksLikeEmployeeRow($row)) {
                    $people[$rowNumber] = ['row' => $row, 'cells' => $cells];
                } else {
                    $sheetNotes[$rowNumber] = implode(' | ', $cells);
                }
            }
            foreach ($sheetNotes as $rowNumber => $text) {
                $target = collect($people)->keys()->first(fn ($r) => str_contains(mb_strtolower($text), mb_strtolower(trim($people[$r]['row']['name']))))
                    ?? collect($people)->keys()->filter(fn ($r) => $r < $rowNumber)->last();
                if ($target === null) { $notes[] = "Sheet \"{$sheet['name']}\" row {$rowNumber}: \"{$text}\" is not under any employee — not imported."; continue; }
                $people[$target]['notes'][] = $text;
            }

            foreach ($people as $rowNumber => $person) {
                $read++;
                $where = "Sheet \"{$sheet['name']}\" row {$rowNumber}";
                [$values, $rowErrors, $rowNotes] = $this->parse($person['row']);
                $values['payroll_month'] = $values['payroll_month'] ?? $sheetMonth;
                if (! $values['payroll_month']) $rowErrors[] = 'Payroll Month is empty (use YYYY-MM).';
                if ($rowErrors) { foreach ($rowErrors as $e) $errors[] = "{$where}: {$e}"; continue; }
                foreach ($rowNotes as $n) $notes[] = "{$where}: {$n}";

                $employee = $this->employeeFor($values['employee_code'] ?? null, $values['name']);
                // Only HRMS employees: a row whose person is not on the Employees page is not saved.
                if (! $employee) { $matchedBy['none'][trim($values['name'])][] = "{$sheet['name']} row {$rowNumber}"; continue; }
                if ($values['employee_code'] ?? null) $matchedBy['code']++; else $matchedBy['name']++;
                $values['employee_id'] = $employee?->id;
                $values['employee_code'] = $employee?->employee_code ?? ($values['employee_code'] ?? null);
                $values['name_key'] = self::nameKey($values['name']);
                $values['notes'] = ! empty($person['notes']) ? implode("\n", $person['notes']) : null;
                $values['raw'] = $this->rawRow($sheet['rows'][$headerRow] ?? [], $person['cells']);
                $values += ['source_file' => $file->getClientOriginalName(), 'source_sheet' => $sheet['name'], 'source_row' => $rowNumber, 'imported_by' => $actorId];

                $existing = LegacySalaryRecord::where('payroll_month', $values['payroll_month'])->where('name_key', $values['name_key'])->first();
                if ($existing && ! $updateExisting) {
                    // Kept as imported, but a row saved before the person was added gets linked to them now
                    // (only the link; amounts and the rest stay exactly as they were).
                    if (! $existing->employee_id) { $existing->update(['employee_id' => $employee->id, 'employee_code' => $employee->employee_code]); $linked++; }
                    $kept++;
                    continue;
                }
                if ($existing) { $existing->update($values); $updated++; } else { LegacySalaryRecord::create($values); $created++; }
                $months[$values['payroll_month']] = true;
            }
        }
        if (! $read && ! $errors) $errors[] = 'No salary rows were found. The sheet needs a header row with "Name" and one row per employee.';
        foreach ($matchedBy['none'] as $name => $where) {
            $errors[] = "\"{$name}\" is not an HRMS employee — ".count($where).' row(s) not imported ('.implode(', ', array_slice($where, 0, 6)).(count($where) > 6 ? ' …' : '').'). Add them on the Employees page, or put their Employee ID in an "Employee ID" column, then import again.';
        }
        if ($linked) $notes[] = "{$linked} row(s) imported earlier were linked to their employee now (nothing else changed).";
        if ($kept) $notes[] = "{$kept} row(s) already imported for that month were kept — tick \"Replace rows that already exist\" to overwrite them.";

        $this->audit->record('salary.legacy_imported', null, ['file' => $file->getClientOriginalName(), 'created' => $created, 'updated' => $updated, 'kept' => $kept, 'skipped' => count($errors), 'months' => array_keys($months)]);
        ksort($months);

        $skipped = count($errors) - count($matchedBy['none']) + array_sum(array_map('count', $matchedBy['none']));

        return [...$this->summary($created, $updated, $kept, $errors, $notes, $read), 'skipped' => $skipped, 'months' => array_keys($months), 'matched' => ['code' => $matchedBy['code'], 'name' => $matchedBy['name'], 'none' => count($matchedBy['none'])]];
    }

    /** "April-2026", "Apr 2026", "2026-04", "04-2026" => "2026-04". */
    public static function monthFromSheetName(string $name): ?string
    {
        $name = trim($name);
        if (preg_match('/^(\d{4})[-\/ ](0?[1-9]|1[0-2])$/', $name, $m)) return sprintf('%04d-%02d', $m[1], $m[2]);
        if (preg_match('/^(0?[1-9]|1[0-2])[-\/ ](\d{4})$/', $name, $m)) return sprintf('%04d-%02d', $m[2], $m[1]);
        if (preg_match('/^([A-Za-z]{3,9})[\s\-_\/\']*(\d{4}|\d{2})$/', $name, $m)) {
            $date = rescue(fn () => Carbon::createFromFormat('!F Y', ucfirst(strtolower($m[1])).' '.(strlen($m[2]) === 2 ? '20'.$m[2] : $m[2])), null, false)
                ?? rescue(fn () => Carbon::createFromFormat('!M Y', ucfirst(strtolower(substr($m[1], 0, 3))).' '.(strlen($m[2]) === 2 ? '20'.$m[2] : $m[2])), null, false);

            return $date?->format('Y-m');
        }

        return null;
    }

    public static function nameKey(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }

    /** Header slug: brackets and their content removed, then lower-case words joined by "_". */
    public static function slug(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
        $header = preg_replace('/\([^)]*\)/', ' ', $header);

        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($header))), '_');
    }

    /** @return array{0: array<int, string>, 1: int} column index => key (or "?Original header"), and the header's row number. */
    private function header(array $rows): array
    {
        foreach (array_slice($rows, 0, 10, true) as $rowNumber => $cells) {
            $keys = [];
            foreach ($cells as $index => $text) $keys[$index] = self::ALIASES[self::slug((string) $text)] ?? '?'.trim((string) $text);
            if (in_array('name', $keys, true)) return [$keys, $rowNumber];
        }

        return [[], 0];
    }

    /** A person's row has a name and at least one number (a lone name or text line is a note). */
    private function looksLikeEmployeeRow(array $row): bool
    {
        foreach ($row as $key => $value) {
            if (in_array($key, ['name', 'deduction_reason', 'payment_status', 'comments', 'employee_code', 'payroll_month'], true)) continue;
            if (is_numeric(str_replace([',', '₹'], '', (string) $value))) return true;
        }

        return false;
    }

    /** @return array{0: array<string, mixed>, 1: array<int, string>, 2: array<int, string>} values, errors, notes */
    public function parse(array $row): array
    {
        $values = [];
        $errors = $notes = [];
        foreach (self::COLUMNS as $key => [, $label, $type]) {
            $raw = trim(str_replace(['₹', ','], '', (string) ($row[$key] ?? '')));
            if ($raw === '' || $raw === '-') { $values[$key] = null; continue; }
            switch ($type) {
                case 'month':
                    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw)) { $values[$key] = $raw; break; }
                    if (is_numeric($raw) && (float) $raw > 20000 && (float) $raw < 80000) { $values[$key] = Carbon::create(1899, 12, 30)->addDays((int) $raw)->format('Y-m'); break; }
                    $values[$key] = self::monthFromSheetName($raw) ?? null;
                    if (! $values[$key]) $errors[] = "{$label} \"{$raw}\" must be YYYY-MM (e.g. 2026-04).";
                    break;
                case 'date':
                    if (is_numeric($raw) && (float) $raw > 20000 && (float) $raw < 80000) { $values[$key] = Carbon::create(1899, 12, 30)->addDays((int) $raw)->toDateString(); break; }
                    $date = app(ImportService::class)->parseDate($raw);
                    if ($date) { $values[$key] = $date->toDateString(); break; }
                    $values[$key] = null;
                    $notes[] = "{$label} \"{$raw}\" is not a date — kept only in the stored original row.";
                    break;
                case 'number':
                case 'days':
                case 'amount':
                    if (! is_numeric($raw)) { $errors[] = "{$label} \"{$raw}\" is not a number."; break; }
                    $values[$key] = (float) $raw; // stored as written, not rounded
                    if ($type === 'days' && abs((float) $raw * 2 - round((float) $raw * 2)) > 1e-6) $notes[] = "{$label} {$raw} is not in steps of 0.5 days — stored as written.";
                    break;
                default:
                    $values[$key] = mb_substr($raw, 0, in_array($key, ['deduction_reason', 'comments'], true) ? 5000 : 255);
            }
        }
        if (($values['name'] ?? null) === null) $errors[] = 'Name is empty.';

        return [$values, $errors, $notes];
    }

    /** By Employee ID; else exact name; else the one active employee whose name starts with it ("Abheeshri" → "Abheeshri Parmar"). */
    private function employeeFor(?string $code, string $name): ?AttendanceEmployee
    {
        if ($code) {
            $employee = AttendanceEmployee::where('employee_code', trim($code))->first();
            if ($employee) return $employee;
        }
        $key = self::nameKey($name);
        $all = AttendanceEmployee::get(['id', 'name', 'employee_code', 'is_active']);
        $exact = $all->filter(fn ($e) => self::nameKey($e->name) === $key);
        if ($exact->count() === 1) return $exact->first();
        // A first name only ("Lalit"): the one person whose name starts with it — current staff first, then
        // ex-employees too (old salary sheets are mostly about people who have left).
        $starts = $all->filter(fn ($e) => str_starts_with(self::nameKey($e->name).' ', $key.' '));
        $active = $starts->where('is_active', true);
        if ($active->count() === 1) return $active->first();

        return $active->isEmpty() && $starts->count() === 1 ? $starts->first() : null;
    }

    /** The row as written: header text => cell value (unrounded formula results, Excel date serials). */
    private function rawRow(array $headerCells, array $cells): array
    {
        $raw = [];
        foreach ($cells as $index => $value) $raw[trim((string) ($headerCells[$index] ?? XlsxWorkbook::letter($index)))] = $value;

        return $raw;
    }

    /** Template: the sheet's own layout (one sheet per month, A–AM with its formulas) plus Employee ID, a column guide and the formulas. */
    public static function templateSheets(): array
    {
        $style = ['text' => 'text', 'month' => 'text', 'number' => 'decimal', 'days' => 'decimal', 'amount' => 'currency', 'date' => 'date'];
        $keys = array_keys(array_filter(self::COLUMNS, fn ($c) => $c[0] !== 'AO'));
        $columns = array_map(fn ($k) => [self::COLUMNS[$k][1], $style[self::COLUMNS[$k][2]], max(12, min(30, (int) (mb_strlen(self::COLUMNS[$k][1]) * 0.7) + 4))], $keys);
        $rows = [];
        foreach (self::SAMPLE as $sample) {
            $rows[] = array_map(fn ($k) => self::COLUMNS[$k][3] ? ['f' => self::COLUMNS[$k][3]] : ($sample[$k] ?? ''), $keys);
        }
        $validations = [];
        foreach ($keys as $i => $k) {
            $col = XlsxWorkbook::letter($i);
            $range = "{$col}2:{$col}500";
            if (self::COLUMNS[$k][3]) continue;
            match (self::COLUMNS[$k][2]) {
                'days' => $validations[] = ['type' => 'custom', 'formula1' => "AND(ISNUMBER({$col}2),{$col}2>=0,MOD({$col}2*2,1)=0)", 'range' => $range, 'error' => 'Days in steps of 0.5 (e.g. 1, 1.5).'],
                'amount' => $validations[] = ['type' => 'custom', 'formula1' => "AND(ISNUMBER({$col}2),ROUND({$col}2,2)={$col}2)", 'range' => $range, 'error' => 'Amount with at most 2 decimals.'],
                'number' => $validations[] = ['type' => 'decimal', 'operator' => 'greaterThanOrEqual', 'formula1' => '0', 'range' => $range, 'error' => 'A number, 0 or more.'],
                'date' => $validations[] = ['type' => 'date', 'operator' => 'greaterThan', 'formula1' => '36526', 'range' => $range, 'error' => 'Enter a date.'],
                default => null,
            };
        }
        $validations[] = ['type' => 'list', 'formula1' => '"Paid,Partially Paid,Unpaid"', 'range' => 'AL2:AL500', 'error' => 'Paid, Partially Paid or Unpaid.'];
        $typeName = ['text' => 'Text', 'month' => 'Text YYYY-MM', 'number' => 'Number', 'days' => 'Decimal (0.5 steps)', 'amount' => 'Currency (₹)', 'date' => 'Date'];
        $guide = array_map(fn ($k) => [self::COLUMNS[$k][0], self::COLUMNS[$k][1], $typeName[self::COLUMNS[$k][2]], self::COLUMNS[$k][3] ? str_replace('{r}', '', self::COLUMNS[$k][3]) : 'entered', self::COLUMNS[$k][4]], array_keys(self::COLUMNS));

        return [
            ['name' => 'April-2026', 'columns' => $columns, 'rows' => $rows, 'validations' => $validations],
            ['name' => 'Columns & formulas', 'freeze' => true, 'columns' => [['Column', 'text', 8], ['Header', 'text', 40], ['Type', 'text', 20], ['Formula', 'text', 22], ['Meaning', 'wrap', 60]], 'rows' => array_merge($guide, [
                ['', '', '', '', ''],
                ['', 'How to use', '', '', 'One sheet per month, named like "April-2026" (copy the sheet for May-2026 …). One row per employee. Free-text lines under a row (e.g. "3000 paid 12 Oct") are kept as notes on that row. HRMS stores every value as written — nothing is recalculated.'],
            ])],
        ];
    }

    /** Sample CSV (one flat sheet, so it carries a Payroll Month column), formula columns worked out like the sheet. */
    public static function sampleCsv(): string
    {
        $keys = array_keys(self::COLUMNS);
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_map(fn ($k) => self::COLUMNS[$k][1], $keys));
        foreach (self::SAMPLE as $s) {
            $v = $s + array_fill_keys($keys, 0);
            $v['salary_per_day'] = $v['basic_salary'] / $v['total_working_days'];
            $v['per_hour_salary'] = $v['salary_per_day'] / 9;
            $v['per_minute_salary'] = $v['per_hour_salary'] / 60;
            $v['total_lunch_time'] = 45 * $v['present_days'];
            $v['leave_days'] = $v['total_working_days'] - $v['present_days'];
            $v['saturday_bonus'] = $v['paid_leave'] * $v['per_minute_salary'];
            $v['late_and_hourly_leave_minutes'] = $v['late_coming_minutes'] + $v['hourly_leave_minutes'];
            $v['late_coming_deduction'] = $v['late_and_hourly_leave_minutes'] * $v['per_minute_salary'];
            $v['late_coming_adjusted'] = $v['adjusted_late_coming_minutes'] * $v['per_minute_salary'];
            $v['short_leave_deduction'] = $v['short_leave_minutes'] * 1.5;
            $v['without_application_deduction'] = $v['without_application_leave'] * $v['salary_per_day'] / 2;
            $v['total_payment_deduction'] = $v['short_leave_deduction'] + $v['without_application_deduction'] + $v['late_coming_deduction'] - $v['late_coming_adjusted'];
            $v['total_salary_paid_days'] = $v['present_days'] + $v['paid_leave'];
            $v['salary_before_adjustment'] = $v['total_salary_paid_days'] * $v['salary_per_day'];
            $v['salary_after_deduction'] = $v['salary_before_adjustment'] - $v['total_payment_deduction'] - $v['tds'];
            $v['total_salary_with_bonus'] = $v['increment_bonus'] + $v['salary_after_deduction'];
            $v['salary_remains'] = $v['total_salary_with_bonus'] - $v['salary_paid'];
            $v['payroll_month'] = '2026-04';
            $v['employee_code'] = '';
            $v['deduction_reason'] = $v['deduction_reason'] ?: '';
            $v['comments'] = '';
            fputcsv($out, array_map(fn ($k) => is_float($v[$k]) ? rtrim(rtrim(sprintf('%.6f', $v[$k]), '0'), '.') : $v[$k], $keys));
        }
        rewind($out);

        return "\xEF\xBB\xBF".stream_get_contents($out);
    }

    /** CSV rows keyed by row number (1-based), like XlsxReader::sheets(). */
    private function csvRows(string $path): array
    {
        $handle = @fopen($path, 'r');
        if (! $handle) throw new \RuntimeException('The file could not be read.');
        $first = fgets($handle);
        rewind($handle);
        $delimiter = $first !== false && substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $rows = [];
        $n = 0;
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $n++;
            $cells = array_filter(array_map(fn ($v) => trim((string) $v), $data), fn ($v) => $v !== '');
            if ($cells) $rows[$n] = $cells;
        }
        fclose($handle);
        if ($rows && isset($rows[1])) $rows[1] = array_map(fn ($v) => preg_replace('/^\xEF\xBB\xBF/', '', $v), $rows[1]);

        return $rows;
    }

    private function summary(int $created = 0, int $updated = 0, int $kept = 0, array $errors = [], array $notes = [], int $rows = 0): array
    {
        return ['rows' => $rows, 'created' => $created, 'updated' => $updated, 'kept' => $kept, 'skipped' => count($errors), 'errors' => array_slice($errors, 0, 100), 'notes' => array_slice($notes, 0, 80), 'more_errors' => max(0, count($errors) - 100)];
    }
}
