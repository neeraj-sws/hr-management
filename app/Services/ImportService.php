<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\PayrollRun;
use App\Models\SalaryProfile;
use App\Support\XlsxReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Bulk import of historical salary and attendance records from the Excel
 * template (.xlsx) or a CSV, so existing data can be loaded without
 * re-entering each row by hand. Every row is validated on its own; a bad row
 * is skipped and reported with its row number and the reason. Existing
 * records are kept unless "update existing records" is ticked, and rows that
 * fall inside a locked payroll period are refused to protect finalised pay.
 */
class ImportService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Columns the salary file understands (header row, case-insensitive). */
    public const SALARY_COLUMNS = ['employee_code', 'monthly_salary', 'effective_from', 'working_days_per_month', 'standard_daily_hours'];
    public const SALARY_REQUIRED = ['employee_code', 'monthly_salary', 'effective_from'];

    /** Columns the attendance file understands. */
    public const ATTENDANCE_COLUMNS = ['employee_code', 'work_date', 'status', 'check_in', 'lunch_in', 'lunch_out', 'check_out', 'break_minutes', 'total_working_hours', 'total_lunch_minutes', 'net_working_hours', 'late_coming_minute', 'email_send_date', 'email_sent_delay'];
    /** check_in is also required, except on Leave / Absent / Holiday / Weekly off rows. */
    public const ATTENDANCE_REQUIRED = ['employee_code', 'work_date'];
    /** Status values that mean "no attendance that day" (time columns stay blank). */
    public const NO_ATTENDANCE_STATUSES = ['leave', 'absent', 'holiday', 'weekly off', 'weekly_off', 'week off', 'wo', 'l', 'a', 'h', 'off'];
    /** Worked out by HRMS from the times; accepted in the file but not imported. */
    public const ATTENDANCE_CALCULATED = ['total_working_hours', 'total_lunch_minutes', 'net_working_hours', 'late_coming_minute', 'email_send_date', 'email_sent_delay'];

    /** What each column means, shown next to the upload form and used by the template. */
    public const HELP = [
        'salary' => [
            'employee_code' => 'Employee code exactly as in Employees (e.g. EMP-001)',
            'monthly_salary' => 'Monthly salary, numbers only (e.g. 25000 or 25000.50)',
            'effective_from' => 'Date the salary starts: YYYY-MM-DD, DD-MM-YYYY, DD/MM/YYYY or an Excel date',
            'working_days_per_month' => 'Optional, 1–31 (default 22)',
            'standard_daily_hours' => 'Optional, hours per day, e.g. 8 or 8.5 (default: the employee\'s own hours)',
        ],
        'attendance' => [
            'employee_code' => 'Employee code exactly as in Employees (e.g. EMP-001)',
            'work_date' => 'Date worked: YYYY-MM-DD, DD-MM-YYYY, DD/MM/YYYY or an Excel date',
            'status' => 'Optional: Present, Half Day, Leave, Absent, Holiday or Weekly Off. Leave / Absent / Holiday rows create no attendance and their time columns stay blank',
            'check_in' => 'Punch In Time: 09:30 (24-hour HH:MM), 9:30 AM or an Excel time. Required for Present rows',
            'lunch_in' => 'Optional Lunch In Time (going for lunch), HH:MM',
            'lunch_out' => 'Optional Lunch Out Time (back from lunch), HH:MM — the lunch (Lunch Out − Lunch In) is the break',
            'check_out' => 'Punch Out Time, HH:MM (earlier than Punch In = next morning, night shift)',
            'break_minutes' => 'Optional total break in minutes (e.g. 45) or HH:MM, used when there are no lunch times',
            'total_working_hours' => 'Calculated: Punch Out − Punch In (HH:MM). Not imported — HRMS works it out',
            'total_lunch_minutes' => 'Calculated: Lunch Out − Lunch In (HH:MM). Not imported',
            'net_working_hours' => 'Calculated: Out Time (After Deduct Lunch Time) = Total Working Hours − Total Lunch. Not imported',
            'late_coming_minute' => 'Calculated: minutes after 08:30, never below 0. Not imported',
            'email_send_date' => 'Optional, YYYY-MM-DD. Kept in the file only',
            'email_sent_delay' => 'Optional whole number. Kept in the file only',
        ],
    ];

    /** Common header spellings people use in their own sheets. */
    private const ALIASES = [
        'emp_code' => 'employee_code', 'code' => 'employee_code', 'employee_id' => 'employee_code', 'emp_id' => 'employee_code',
        'salary' => 'monthly_salary', 'monthly' => 'monthly_salary', 'gross_salary' => 'monthly_salary',
        'effective_date' => 'effective_from', 'from' => 'effective_from', 'start_date' => 'effective_from',
        'working_days' => 'working_days_per_month', 'days_per_month' => 'working_days_per_month',
        'daily_hours' => 'standard_daily_hours', 'hours_per_day' => 'standard_daily_hours', 'work_hours' => 'standard_daily_hours',
        'date' => 'work_date', 'attendance_date' => 'work_date', 'day' => 'work_date',
        'in' => 'check_in', 'in_time' => 'check_in', 'checkin' => 'check_in', 'time_in' => 'check_in', 'punch_in' => 'check_in', 'punch_in_time' => 'check_in',
        'out' => 'check_out', 'out_time' => 'check_out', 'checkout' => 'check_out', 'time_out' => 'check_out', 'punch_out' => 'check_out', 'punch_out_time' => 'check_out',
        'lunch_in_time' => 'lunch_in', 'lunch_start' => 'lunch_in', 'lunch_out_time' => 'lunch_out', 'lunch_end' => 'lunch_out',
        'out_time_after_deduct_lunch_time' => 'net_working_hours', 'working_hours' => 'total_working_hours', 'lunch_minutes' => 'total_lunch_minutes', 'late_minutes' => 'late_coming_minute', 'late_coming_minutes' => 'late_coming_minute',
        'break' => 'break_minutes', 'break_time' => 'break_minutes', 'break_mins' => 'break_minutes',
    ];

    public function salary(UploadedFile $file, int $actorId, bool $updateExisting = false): array
    {
        [$rows, $error] = $this->read($file, self::SALARY_COLUMNS, self::SALARY_REQUIRED);
        if ($error) return $this->summary(errors: [$error]);

        $lockedThrough = $this->lockedThrough();
        $created = $updated = $kept = 0;
        $errors = $notes = [];
        $touched = $seen = [];

        foreach ($rows as [$line, $row]) {
            $employee = $this->employee($row, $line, $errors);
            if (! $employee) continue;

            $monthly = str_replace([',', ' '], '', (string) ($row['monthly_salary'] ?? ''));
            if (! is_numeric($monthly) || (float) $monthly < 0) { $errors[] = "Row {$line}: monthly_salary \"{$row['monthly_salary']}\" is not a valid amount (numbers only, e.g. 25000)."; continue; }
            $date = $this->parseDate($row['effective_from'] ?? '');
            if (! $date) { $errors[] = "Row {$line}: effective_from \"{$row['effective_from']}\" is not a valid date (use YYYY-MM-DD or DD-MM-YYYY)."; continue; }
            if ($lockedThrough && $date->toDateString() <= $lockedThrough) { $errors[] = "Row {$line}: payroll is locked through {$lockedThrough}; a salary from {$date->format('d M Y')} cannot be added."; continue; }

            $days = trim((string) ($row['working_days_per_month'] ?? ''));
            if ($days !== '' && (! ctype_digit($days) || (int) $days < 1 || (int) $days > 31)) { $errors[] = "Row {$line}: working_days_per_month \"{$days}\" must be a whole number from 1 to 31."; continue; }
            $hours = trim((string) ($row['standard_daily_hours'] ?? ''));
            if ($hours !== '' && (! is_numeric($hours) || (float) $hours <= 0 || (float) $hours > 24)) { $errors[] = "Row {$line}: standard_daily_hours \"{$hours}\" must be more than 0 and at most 24."; continue; }

            $key = $employee->id.'|'.$date->toDateString();
            if (isset($seen[$key])) { $errors[] = "Row {$line}: duplicate of row {$seen[$key]} (same employee and effective date) — skipped."; continue; }
            $seen[$key] = $line;

            $values = [
                'monthly_salary' => round((float) $monthly, 2),
                'working_days_per_month' => $days !== '' ? (int) $days : 22,
                'standard_daily_hours' => $hours !== '' ? (float) $hours : ((float) $employee->daily_work_hours > 0 ? (float) $employee->daily_work_hours : 8),
                'configured_by' => $actorId,
            ];
            $profile = SalaryProfile::where('employee_id', $employee->id)->whereDate('effective_from', $date->toDateString())->first();
            if ($profile && ! $updateExisting) { $kept++; $notes[] = "Row {$line}: {$employee->employee_code} already has a salary from {$date->format('d M Y')} — kept the existing record."; continue; }
            if ($profile) { $profile->update($values); $updated++; }
            else { SalaryProfile::create(['employee_id' => $employee->id, 'effective_from' => $date->toDateString(), ...$values]); $created++; }
            $touched[$employee->id] = true;
        }

        // Keep the employee's headline salary and working day in step with their latest profile.
        foreach (array_keys($touched) as $employeeId) {
            $latest = SalaryProfile::where('employee_id', $employeeId)->orderByDesc('effective_from')->orderByDesc('id')->first();
            if ($latest) AttendanceEmployee::whereKey($employeeId)->update(['monthly_salary' => $latest->monthly_salary, 'daily_work_hours' => $latest->standard_daily_hours]);
        }

        $this->audit->record('salary.imported', null, ['file' => $file->getClientOriginalName(), 'created' => $created, 'updated' => $updated, 'kept' => $kept, 'skipped' => count($errors), 'update_existing' => $updateExisting]);

        $missingRows = array_sum(array_map('count', $this->missingCodes));
        $skipped = count($errors) + $missingRows;
        $errors = [...$this->missingCodeErrors(), ...$errors];

        return $this->summary($created, $updated, $kept, $errors, $notes, count($rows), $skipped);
    }

    /** $forEmployeeId: the file is one person's sheet — every row is theirs and the Employee ID column is not needed. */
    /** $intoLockedPayroll (admins only): old history may also be saved on days of a locked payroll; that payroll is not recalculated. */
    public function attendance(UploadedFile $file, int $actorId, bool $updateExisting = false, ?int $forEmployeeId = null, bool $intoLockedPayroll = false): array
    {
        $forEmployee = $forEmployeeId ? AttendanceEmployee::find($forEmployeeId) : null;
        [$rows, $error] = $this->read($file, self::ATTENDANCE_COLUMNS, $forEmployee ? ['work_date'] : self::ATTENDANCE_REQUIRED);
        if ($error) return $this->summary(errors: [$error]);
        // Importing for one chosen employee must never take in another person's rows.
        if ($forEmployee && ($problem = $this->otherEmployeesInFile($rows, $forEmployee))) return $this->summary(errors: [$problem], rows: count($rows), skipped: count($rows));
        $guessNote = null;
        if (! $forEmployee && ($forEmployee = $this->employeeFromFileName($rows, $file->getClientOriginalName()))) {
            $guessNote = "The Employee ID in the file is not an HRMS code, so the rows were imported for {$forEmployee->name} ({$forEmployee->employee_code}), whose name is in the file name.";
        }

        // Only days inside a locked payroll run are frozen; older history with no payroll run can be imported.
        $lockedRuns = PayrollRun::where('status', 'locked')->get(['period_starts_on', 'period_ends_on']);
        $created = $updated = $kept = 0;
        $errors = $notes = [];
        $seen = [];

        foreach ($rows as [$line, $row]) {
            if ($forEmployee && trim((string) ($row['work_date'] ?? '')) === '') continue; // totals / blank row
            $employee = $forEmployee ?? $this->employee($row, $line, $errors);
            if (! $employee) continue;

            $date = $this->parseDate($row['work_date'] ?? '');
            if (! $date) { $errors[] = "Row {$line}: work_date \"{$row['work_date']}\" is not a valid date (use YYYY-MM-DD or DD-MM-YYYY)."; continue; }
            if ($date->gt(today())) { $errors[] = "Row {$line}: work_date {$date->format('d M Y')} is in the future."; continue; }
            $lockedRun = $lockedRuns->first(fn ($run) => $date->between($run->period_starts_on, $run->period_ends_on));
            if ($lockedRun && $intoLockedPayroll) {
                $lockedDays[$lockedRun->period_starts_on->format('d M').' – '.$lockedRun->period_ends_on->format('d M Y')] = true;
            } elseif ($lockedRun) { $lockedBlocked[$lockedRun->period_starts_on->format('d M').' – '.$lockedRun->period_ends_on->format('d M Y')][] = $line; continue; }

            $status = strtolower(trim((string) ($row['status'] ?? '')));
            if (self::isNoAttendanceStatus($status)) {
                $timesGiven = collect(['check_in', 'lunch_in', 'lunch_out', 'check_out'])->contains(fn ($c) => trim((string) ($row[$c] ?? '')) !== '');
                $notes[] = "Row {$line}: {$employee->employee_code} {$date->format('d M Y')} is \"".ucwords($status).'" — no attendance created'.($timesGiven ? ' (the time columns should be blank on such rows; they were ignored)' : '').'.';
                continue;
            }
            if ($status !== '' && ! in_array($status, ['present', 'p', 'half day', 'half_day', 'halfday', 'hd', 'wfh', 'work from home'], true)) { $errors[] = "Row {$line}: status \"{$row['status']}\" is not known (use Present, Half Day, Leave, Absent, Holiday or Weekly Off)."; continue; }
            $in = $this->parseTime($date, $row['check_in'] ?? '');
            if (! $in) { $errors[] = "Row {$line}: check_in \"{$row['check_in']}\" is not a valid time (use 09:30 or 9:30 AM)."; continue; }
            $outRaw = trim((string) ($row['check_out'] ?? ''));
            $out = $outRaw !== '' ? $this->parseTime($date, $outRaw) : null;
            if ($outRaw !== '' && ! $out) { $errors[] = "Row {$line}: check_out \"{$outRaw}\" is not a valid time (use 18:30 or 6:30 PM)."; continue; }
            if ($out && $out->lte($in)) $out = $out->addDay(); // night shift: check-out the next morning
            if ($out && $out->gt(now())) { $errors[] = "Row {$line}: check_out is in the future."; continue; }

            // Lunch In / Lunch Out times give the break (Lunch Out − Lunch In); otherwise break_minutes.
            // Excel shows an empty time as 00:00 (0): no lunch taken.
            $noTime = fn (string $v) => $v === '' || $v === '-' || (is_numeric($v) && (float) $v == 0.0) || preg_match('/^0{1,2}:00(:00)?$/', $v);
            $lunchInRaw = trim((string) ($row['lunch_in'] ?? ''));
            $lunchOutRaw = trim((string) ($row['lunch_out'] ?? ''));
            if ($noTime($lunchInRaw) && $noTime($lunchOutRaw)) $lunchInRaw = $lunchOutRaw = '';
            if ($lunchInRaw !== '' || $lunchOutRaw !== '') {
                $lunchIn = $this->parseTime($date, $lunchInRaw);
                $lunchOut = $this->parseTime($date, $lunchOutRaw);
                if (! $lunchIn || ! $lunchOut) { $errors[] = "Row {$line}: give both Lunch In and Lunch Out as HH:MM (or leave both blank)."; continue; }
                if ($lunchIn->lt($in)) $lunchIn = $lunchIn->addDay();
                if ($lunchOut->lt($lunchIn)) $lunchOut = $lunchOut->addDay();
                if ($out && ($lunchIn->lt($in) || $lunchOut->gt($out))) { $errors[] = "Row {$line}: the lunch must be between Punch In and Punch Out."; continue; }
                $row['break_minutes'] = (string) intdiv($lunchIn->diffInSeconds($lunchOut), 60);
            }
            $breakSeconds = $this->parseBreak($row['break_minutes'] ?? '');
            if ($breakSeconds === null) { $errors[] = "Row {$line}: break_minutes \"{$row['break_minutes']}\" must be minutes (e.g. 45) or HH:MM (e.g. 00:45)."; continue; }
            if ($out && $breakSeconds >= $in->diffInSeconds($out)) { $errors[] = "Row {$line}: the break is as long as the whole day; check the times."; continue; }

            $key = $employee->id.'|'.$date->toDateString();
            if (isset($seen[$key])) { $errors[] = "Row {$line}: duplicate of row {$seen[$key]} (same employee and date) — skipped."; continue; }
            $seen[$key] = $line;

            $session = AttendanceSession::where('employee_id', $employee->id)->whereDate('work_date', $date->toDateString())->latest('id')->first();
            if ($session && ! $updateExisting) { $kept++; $notes[] = "Row {$line}: {$employee->employee_code} already has attendance on {$date->format('d M Y')} — kept the existing record."; continue; }
            $changes = [
                'check_in' => $in,
                'check_out' => $out,
                'break_seconds' => $breakSeconds,
                'status' => $out ? 'completed' : 'working',
                'active_seconds' => $out ? (int) max($in->diffInSeconds($out) - $breakSeconds, 0) : 0,
                'last_sync_at' => now(),
            ];
            if ($session) { $session->update($changes); $session->increment('server_version'); $updated++; }
            else { AttendanceSession::create(['employee_id' => $employee->id, 'work_date' => $date->toDateString(), ...$changes]); $created++; }
            $touched[$employee->id][] = $date->toDateString();
        }

        $this->audit->record('attendance.imported', null, ['file' => $file->getClientOriginalName(), 'created' => $created, 'updated' => $updated, 'kept' => $kept, 'skipped' => count($errors) + array_sum(array_map('count', $this->missingCodes)), 'unknown_codes' => array_keys($this->missingCodes), 'for_employee_id' => $forEmployee?->id, 'update_existing' => $updateExisting]);

        $missingRows = array_sum(array_map('count', $this->missingCodes));
        $skipped = count($errors) + $missingRows;
        $unknownCodes = array_keys($this->missingCodes);
        // One line per locked payroll month instead of one per row.
        foreach ($lockedBlocked ?? [] as $period => $lines) {
            $skipped += count($lines);
            $where = count($lines) > 3 ? 'rows '.$lines[0].'–'.end($lines).' ('.count($lines).' rows)' : 'row '.implode(', ', $lines);
            $errors[] = "Payroll for {$period} is locked, so {$where} were not imported. For old history an administrator can tick \"Old data: also import into locked payroll months\" (the locked payroll stays as it is), or reopen that payroll first.";
        }
        $errors = [...$this->missingCodeErrors(), ...$errors];

        if ($guessNote) array_unshift($notes, $guessNote);
        if (! empty($lockedDays)) array_unshift($notes, 'Saved as history inside locked payroll period(s) '.implode(', ', array_keys($lockedDays)).'; those locked payrolls were not recalculated.');

        // Where the imported days can be seen: the employee(s) and the date range.
        $allDates = collect($touched ?? [])->flatten()->sort()->values();
        $imported = $allDates->isEmpty() ? null : ['employee_ids' => array_keys($touched), 'from' => $allDates->first(), 'to' => $allDates->last()];

        return [...$this->summary($created, $updated, $kept, $errors, $notes, count($rows), $skipped), 'unknown_codes' => $unknownCodes, 'imported' => $imported];
    }

    /** Header row plus one example row for the downloadable template. */
    public static function template(string $type): array
    {
        return match ($type) {
            'salary' => [self::SALARY_COLUMNS, []],
            'attendance' => [['employee_code', 'work_date', 'check_in', 'check_out', 'break_minutes'], []],
            default => abort(404),
        };
    }

    /** The 13 columns of the attendance sheet, in order: [header, style, width]. */
    public const ATTENDANCE_SHEET = [
        ['Employee ID', 'text', 13], ['Date', 'date', 12], ['Status', 'text', 12], ['Punch In Time', 'time', 13], ['Lunch In Time', 'time', 13], ['Lunch Out Time', 'time', 14],
        ['Punch Out Time', 'time', 14], ['Total Working Hours', 'duration', 15], ['Total Lunch Minutes', 'duration', 15], ['Out Time (After Deduct Lunch Time)', 'duration', 20],
        ['Late Coming Minute', 'integer', 13], ['Email Send Date', 'date', 14], ['Email Sent Delay', 'integer', 13],
    ];

    /** Sample rows: Present, Present (late), Half Day, Leave, Holiday. Times blank on Leave / Holiday. */
    public const ATTENDANCE_SAMPLE = [
        ['EMP-001', '2026-10-01', 'Present', '08:25', '13:30', '14:00', '18:15', '2026-10-01', 0],
        ['EMP-002', '2026-10-01', 'Present', '08:47', '13:15', '13:50', '18:30', '2026-10-02', 1],
        ['EMP-003', '2026-10-01', 'Half Day', '08:30', '', '', '13:00', '', ''],
        ['EMP-004', '2026-10-01', 'Leave', '', '', '', '', '', ''],
        ['EMP-001', '2026-10-02', 'Holiday', '', '', '', '', '', ''],
    ];

    /** Formulas of the calculated columns (row {r}); Excel works them out, HRMS recalculates them on import. */
    public const ATTENDANCE_FORMULAS = [
        'H' => ['Total Working Hours', 'Punch Out Time − Punch In Time', '=IF(OR(D{r}="",G{r}=""),"",G{r}-D{r})'],
        'I' => ['Total Lunch Minutes', 'Lunch Out Time − Lunch In Time', '=IF(OR(E{r}="",F{r}=""),"",F{r}-E{r})'],
        'J' => ['Out Time (After Deduct Lunch Time)', 'Total Working Hours − Total Lunch Minutes', '=IF(H{r}="","",H{r}-IF(I{r}="",0,I{r}))'],
        'K' => ['Late Coming Minute', 'Minutes after 08:30, never below 0: MAX(0, (Punch In − 08:30) × 1440)', '=IF(D{r}="","",MAX(0,ROUND((D{r}-TIME(8,30,0))*1440,0)))'],
    ];

    /** Attendance template workbook: data sheet with formulas and validation, plus a column guide. */
    public static function attendanceTemplateSheets(): array
    {
        $rows = [];
        foreach (self::ATTENDANCE_SAMPLE as [$code, $date, $status, $in, $lunchIn, $lunchOut, $out, $mailDate, $delay]) {
            $rows[] = [$code, $date, $status, $in, $lunchIn, $lunchOut, $out,
                ['f' => self::ATTENDANCE_FORMULAS['H'][2]], ['f' => self::ATTENDANCE_FORMULAS['I'][2]], ['f' => self::ATTENDANCE_FORMULAS['J'][2]], ['f' => self::ATTENDANCE_FORMULAS['K'][2]],
                $mailDate, $delay === '' ? '' : (int) $delay];
        }
        $timeRule = fn ($col) => ['type' => 'time', 'operator' => 'between', 'formula1' => '0', 'formula2' => '0.999988425925926', 'range' => "{$col}2:{$col}1000", 'error' => 'Enter a time as HH:MM (24-hour), e.g. 09:30.'];
        $guide = [
            ['A', 'Employee ID', 'Text', 'Yes', 'Employee code as in HRMS (e.g. EMP-001).'],
            ['B', 'Date', 'Date YYYY-MM-DD', 'Yes', 'Day of the attendance.'],
            ['C', 'Status', 'Text (list)', 'No', 'Present, Half Day, Leave, Absent, Holiday or Weekly Off. Leave / Absent / Holiday rows: leave all time columns blank — no attendance is created.'],
            ['D', 'Punch In Time', 'Time HH:MM (24-hour)', 'Present rows', 'First check-in.'],
            ['E', 'Lunch In Time', 'Time HH:MM', 'No', 'Going for lunch.'],
            ['F', 'Lunch Out Time', 'Time HH:MM', 'No', 'Back from lunch. The lunch (F − E) is saved as the break.'],
            ['G', 'Punch Out Time', 'Time HH:MM', 'No', 'Last check-out (earlier than Punch In = next morning, night shift).'],
            ['H', 'Total Working Hours', 'Time HH:MM (formula)', 'No', self::ATTENDANCE_FORMULAS['H'][1].'. Not imported — HRMS recalculates it.'],
            ['I', 'Total Lunch Minutes', 'Time HH:MM (formula)', 'No', self::ATTENDANCE_FORMULAS['I'][1].'. Not imported.'],
            ['J', 'Out Time (After Deduct Lunch Time)', 'Time HH:MM (formula)', 'No', self::ATTENDANCE_FORMULAS['J'][1].' = the net working time. Not imported.'],
            ['K', 'Late Coming Minute', 'Integer (formula)', 'No', self::ATTENDANCE_FORMULAS['K'][1].'. Not imported.'],
            ['L', 'Email Send Date', 'Date YYYY-MM-DD', 'No', 'Kept in the file only.'],
            ['M', 'Email Sent Delay', 'Integer', 'No', 'Kept in the file only.'],
        ];

        return [
            ['name' => 'Attendance', 'columns' => self::ATTENDANCE_SHEET, 'rows' => $rows, 'validations' => [
                ['type' => 'list', 'formula1' => '"Present,Half Day,Leave,Absent,Holiday,Weekly Off"', 'range' => 'C2:C1000', 'error' => 'Choose a status from the list.'],
                ['type' => 'date', 'operator' => 'greaterThan', 'formula1' => '36526', 'range' => 'B2:B1000', 'error' => 'Enter a date as YYYY-MM-DD.'],
                $timeRule('D'), $timeRule('E'), $timeRule('F'), $timeRule('G'),
                ['type' => 'date', 'operator' => 'greaterThan', 'formula1' => '36526', 'range' => 'L2:L1000', 'error' => 'Enter a date as YYYY-MM-DD.'],
                ['type' => 'whole', 'operator' => 'greaterThanOrEqual', 'formula1' => '0', 'range' => 'M2:M1000', 'error' => 'Whole number, 0 or more.'],
            ]],
            ['name' => 'Columns', 'columns' => [['Column', 'text', 8], ['Header', 'text', 34], ['Format', 'text', 22], ['Required', 'text', 12], ['Rule', 'wrap', 80]], 'rows' => $guide],
        ];
    }

    /** Sample CSV of the 13-column sheet (calculated columns filled in, as Excel would show them). */
    public static function attendanceSampleCsv(): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_column(self::ATTENDANCE_SHEET, 0));
        $minutes = fn (?string $t) => $t ? ((int) substr($t, 0, 2)) * 60 + (int) substr($t, 3, 2) : null;
        $hhmm = fn (?int $m) => $m === null ? '' : sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        foreach (self::ATTENDANCE_SAMPLE as [$code, $date, $status, $in, $lunchIn, $lunchOut, $out2, $mailDate, $delay]) {
            $total = $in && $out2 ? $minutes($out2) - $minutes($in) : null;
            $lunch = $lunchIn && $lunchOut ? $minutes($lunchOut) - $minutes($lunchIn) : null;
            $late = $in ? max(0, $minutes($in) - 510) : null;
            fputcsv($out, [$code, $date, $status, $in, $lunchIn, $lunchOut, $out2, $hhmm($total), $hhmm($lunch), $total === null ? '' : $hhmm($total - ($lunch ?? 0)), $late ?? '', $mailDate, $delay]);
        }
        rewind($out);

        return "\xEF\xBB\xBF".stream_get_contents($out);
    }

    /** Leave, absent, holiday and any kind of day off (Weekly Off, Third Saturday, Sunday, Comp Off …) create no attendance. */
    public static function isNoAttendanceStatus(string $status): bool
    {
        $status = strtolower(trim($status));

        return in_array($status, self::NO_ATTENDANCE_STATUSES, true)
            || (bool) preg_match('/\b(leave|absent|holiday|off|saturday|sunday)\b/', $status);
    }

    /** "EMP-001" codes that were not found => rows, so one missing code is reported once (not on every row). */
    private array $missingCodes = [];

    /**
     * The employee of a row: by employee code, else by e-mail or exact name when that is unique.
     * Rows without an Employee ID and a date (e.g. a totals row under the data) are skipped quietly.
     */
    private function employee(array $row, int $line, array &$errors): ?AttendanceEmployee
    {
        $code = trim((string) ($row['employee_code'] ?? ''));
        if ($code === '') {
            if (trim((string) ($row['work_date'] ?? $row['effective_from'] ?? '')) === '') return null; // totals / blank row
            $errors[] = "Row {$line}: Employee ID is empty.";

            return null;
        }
        $employee = AttendanceEmployee::where('employee_code', $code)->first()
            ?? AttendanceEmployee::where('email', $code)->first();
        if (! $employee) {
            $byName = AttendanceEmployee::whereRaw('LOWER(name) = ?', [mb_strtolower($code)])->limit(2)->get();
            if ($byName->count() === 1) $employee = $byName->first();
        }
        if (! $employee) $this->missingCodes[$code][] = $line;

        return $employee;
    }

    /**
     * One person's sheet whose Employee ID is not an HRMS code (e.g. a template's EMP-001): when the file name
     * names exactly one active employee ("attendance-khushboo-april.xlsx"), the rows are theirs.
     */
    private function employeeFromFileName(array $rows, string $fileName): ?AttendanceEmployee
    {
        $codes = collect($rows)->map(fn ($r) => trim((string) ($r[1]['employee_code'] ?? '')))->filter()->unique();
        if ($codes->count() !== 1) return null;
        $code = $codes->first();
        if (AttendanceEmployee::where('employee_code', $code)->orWhere('email', $code)->orWhereRaw('LOWER(name) = ?', [mb_strtolower($code)])->exists()) return null;
        $words = collect(preg_split('/[^a-z]+/', mb_strtolower(pathinfo($fileName, PATHINFO_FILENAME))))->filter(fn ($w) => mb_strlen($w) >= 4);
        $matches = AttendanceEmployee::where('is_active', true)->get(['id', 'name', 'employee_code'])
            ->filter(fn ($e) => $words->contains(fn ($w) => in_array($w, preg_split('/\s+/', mb_strtolower($e->name)), true)));

        return $matches->count() === 1 ? AttendanceEmployee::find($matches->first()->id) : null;
    }

    /**
     * Why a file cannot be imported for $for: it has rows for several people, or its Employee ID is another
     * employee's. A blank ID, $for's own code / e-mail / name, or one ID unknown to HRMS (the sheet's own numbering) is fine.
     */
    private function otherEmployeesInFile(array $rows, AttendanceEmployee $for): ?string
    {
        $own = array_map('mb_strtolower', array_filter([$for->employee_code, $for->email, $for->name]));
        $codes = collect($rows)->map(fn ($r) => trim((string) ($r[1]['employee_code'] ?? '')))->filter()
            ->reject(fn ($c) => in_array(mb_strtolower($c), $own, true))->unique()->values();
        if ($codes->count() > 1) {
            return "This file has rows for several employees (Employee ID ".$codes->take(5)->map(fn ($c) => "\"{$c}\"")->join(', ').($codes->count() > 5 ? ' …' : '')."), so it was not imported for {$for->name}. Import it with \"Many employees: use the Employee ID column\", or upload only {$for->name}'s sheet.";
        }
        if ($codes->count() === 1) {
            $code = $codes->first();
            $other = AttendanceEmployee::where('employee_code', $code)->orWhere('email', $code)->orWhereRaw('LOWER(name) = ?', [mb_strtolower($code)])->first();
            if ($other && $other->id !== $for->id) return "This sheet belongs to {$other->name} ({$other->employee_code}), not {$for->name}, so nothing was imported. Choose {$other->name} — or fix the Employee ID in the file.";
        }

        return null;
    }

    /** One error per unknown Employee ID, with the rows it was on. */
    private function missingCodeErrors(): array
    {
        $errors = [];
        foreach ($this->missingCodes as $code => $lines) {
            $where = count($lines) > 3 ? 'rows '.$lines[0].'–'.end($lines).' ('.count($lines).' rows)' : 'row '.implode(', ', $lines);
            $errors[] = "No employee with Employee ID \"{$code}\" ({$where}). Put the employee's code exactly as shown on the Employees page (their e-mail or full name also works) — or, for one person's sheet, choose them in \"Import for employee\" and import again.";
        }
        $this->missingCodes = [];

        return $errors;
    }

    /**
     * Reads a .xlsx or .csv into [line number, header-keyed row] pairs.
     *
     * @return array{0: array<int, array{0: int, 1: array<string, string>}>, 1: ?string}  [rows, fatal error]
     */
    private function read(UploadedFile $file, array $known, array $required): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        try {
            $raw = $extension === 'xlsx' ? XlsxReader::rows($file->getRealPath()) : $this->csvRows($file->getRealPath());
        } catch (\Throwable $e) {
            return [[], $e->getMessage()];
        }
        if (! $raw) return [[], 'The file is empty.'];

        $header = array_map(function ($h) {
            $h = preg_replace('/^\xEF\xBB\xBF/', '', (string) $h);
            $h = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($h))), '_');

            return self::ALIASES[$h] ?? $h;
        }, array_shift($raw));

        $missing = array_diff($required, $header);
        if ($missing) return [[], 'Missing required column(s): '.implode(', ', $missing).'. The first row must be the header: '.implode(', ', $known).'. Download the template to see the exact columns.'];
        $unknown = array_values(array_filter(array_diff($header, $known), fn ($h) => $h !== ''));
        if ($unknown) return [[], 'Unknown column(s): '.implode(', ', $unknown).'. Allowed columns: '.implode(', ', $known).'.'];

        $rows = [];
        foreach ($raw as $i => $data) {
            if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) continue; // blank line
            $row = [];
            foreach ($header as $index => $name) if ($name !== '') $row[$name] = isset($data[$index]) ? trim((string) $data[$index]) : '';
            $rows[] = [$i + 2, $row]; // header is row 1
        }
        if (! $rows) return [[], 'The file has a header but no data rows.'];
        if (count($rows) > 5000) return [[], 'The file has '.count($rows).' rows; import at most 5000 rows at a time.'];

        return [$rows, null];
    }

    private function csvRows(string $path): array
    {
        $handle = @fopen($path, 'r');
        if (! $handle) throw new \RuntimeException('The file could not be read.');
        $first = fgets($handle);
        rewind($handle);
        $delimiter = $first !== false && substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $rows = [];
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) $rows[] = $data;
        fclose($handle);

        return $rows;
    }

    private function lockedThrough(): ?string
    {
        $value = PayrollRun::where('status', 'locked')->max('period_ends_on');

        return $value ? substr((string) $value, 0, 10) : null;
    }

    /**
     * YYYY-MM-DD, DD-MM-YYYY, DD/MM/YYYY, DD.MM.YYYY, 03-Oct-2026 or an Excel
     * date serial. Day-first, as dates are written in India; never guesses
     * US month-first order.
     */
    public function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') return null;
        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) floor((float) $value))->startOfDay();
        }
        $value = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?\s*([AaPp][Mm])?$/', '', $value); // drop a time part
        foreach (['Y-m-d', 'Y-n-j', 'd-m-Y', 'j-n-Y', 'd/m/Y', 'j/n/Y', 'd.m.Y', 'j.n.Y', 'Y/m/d', 'd-M-Y', 'j-M-Y', 'd M Y', 'j M Y', 'd-m-y', 'd/m/y'] as $format) {
            $date = rescue(fn () => Carbon::createFromFormat('!'.$format, $value), null, false);
            // Round-trip check: rejects impossible dates such as 31-02-2026 instead of rolling them over.
            if ($date && strcasecmp($date->format($format), $value) === 0) return $date->startOfDay();
        }

        return null;
    }

    /** 09:30, 9:30:15, 9:30 AM, 9.30, or an Excel time (fraction of a day). */
    public function parseTime(Carbon $date, string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') return null;
        if (is_numeric($value) && ! str_contains($value, ':')) {
            $fraction = fmod((float) $value, 1.0);
            if ((float) $value >= 1 && $fraction == 0.0) return null;
            if ((float) $value > 0 && ((float) $value < 1 || $fraction > 0)) {
                return $date->copy()->startOfDay()->addSeconds((int) round($fraction * 86400));
            }
        }
        if (! preg_match('/^(\d{1,2})[:.](\d{2})(?::(\d{2}))?\s*([AaPp][Mm])?$/', $value, $m)) return null;
        [$hour, $minute, $second] = [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];
        if (! empty($m[4])) {
            if ($hour < 1 || $hour > 12) return null;
            $pm = strtolower($m[4]) === 'pm';
            $hour = $hour % 12 + ($pm ? 12 : 0);
        }
        if ($hour > 23 || $minute > 59 || $second > 59) return null;

        return $date->copy()->setTime($hour, $minute, $second);
    }

    /** Minutes (45), HH:MM (00:45) or an Excel time; '' means no break. */
    private function parseBreak(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') return 0;
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) ($m[3] ?? 0);
        if (is_numeric($value) && (float) $value > 0 && (float) $value < 1) return (int) round((float) $value * 86400);
        if (is_numeric($value) && (float) $value >= 0 && (float) $value <= 720) return (int) round((float) $value * 60);

        return null;
    }

    private function summary(int $created = 0, int $updated = 0, int $kept = 0, array $errors = [], array $notes = [], int $rows = 0, ?int $skipped = null): array
    {
        return ['rows' => $rows, 'created' => $created, 'updated' => $updated, 'kept' => $kept, 'skipped' => $skipped ?? count($errors), 'errors' => array_slice($errors, 0, 100), 'notes' => array_slice($notes, 0, 50), 'more_errors' => max(0, count($errors) - 100)];
    }
}
