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
    public const ATTENDANCE_COLUMNS = ['employee_code', 'work_date', 'check_in', 'check_out', 'break_minutes'];
    public const ATTENDANCE_REQUIRED = ['employee_code', 'work_date', 'check_in'];

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
            'check_in' => 'Check-in time: 09:30, 9:30 AM or an Excel time',
            'check_out' => 'Optional check-out time (earlier than check-in = next morning, night shift)',
            'break_minutes' => 'Optional total break in minutes (e.g. 45) or HH:MM (e.g. 00:45)',
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
        'in' => 'check_in', 'in_time' => 'check_in', 'checkin' => 'check_in', 'time_in' => 'check_in',
        'out' => 'check_out', 'out_time' => 'check_out', 'checkout' => 'check_out', 'time_out' => 'check_out',
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

        return $this->summary($created, $updated, $kept, $errors, $notes, count($rows));
    }

    public function attendance(UploadedFile $file, int $actorId, bool $updateExisting = false): array
    {
        [$rows, $error] = $this->read($file, self::ATTENDANCE_COLUMNS, self::ATTENDANCE_REQUIRED);
        if ($error) return $this->summary(errors: [$error]);

        $lockedThrough = $this->lockedThrough();
        $created = $updated = $kept = 0;
        $errors = $notes = [];
        $seen = [];

        foreach ($rows as [$line, $row]) {
            $employee = $this->employee($row, $line, $errors);
            if (! $employee) continue;

            $date = $this->parseDate($row['work_date'] ?? '');
            if (! $date) { $errors[] = "Row {$line}: work_date \"{$row['work_date']}\" is not a valid date (use YYYY-MM-DD or DD-MM-YYYY)."; continue; }
            if ($date->gt(today())) { $errors[] = "Row {$line}: work_date {$date->format('d M Y')} is in the future."; continue; }
            if ($lockedThrough && $date->toDateString() <= $lockedThrough) { $errors[] = "Row {$line}: payroll is locked through {$lockedThrough}; attendance on {$date->format('d M Y')} cannot change."; continue; }

            $in = $this->parseTime($date, $row['check_in'] ?? '');
            if (! $in) { $errors[] = "Row {$line}: check_in \"{$row['check_in']}\" is not a valid time (use 09:30 or 9:30 AM)."; continue; }
            $outRaw = trim((string) ($row['check_out'] ?? ''));
            $out = $outRaw !== '' ? $this->parseTime($date, $outRaw) : null;
            if ($outRaw !== '' && ! $out) { $errors[] = "Row {$line}: check_out \"{$outRaw}\" is not a valid time (use 18:30 or 6:30 PM)."; continue; }
            if ($out && $out->lte($in)) $out = $out->addDay(); // night shift: check-out the next morning
            if ($out && $out->gt(now())) { $errors[] = "Row {$line}: check_out is in the future."; continue; }

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
        }

        $this->audit->record('attendance.imported', null, ['file' => $file->getClientOriginalName(), 'created' => $created, 'updated' => $updated, 'kept' => $kept, 'skipped' => count($errors), 'update_existing' => $updateExisting]);

        return $this->summary($created, $updated, $kept, $errors, $notes, count($rows));
    }

    /** Header row plus one example row for the downloadable template. */
    public static function template(string $type): array
    {
        return match ($type) {
            'salary' => [self::SALARY_COLUMNS, []],
            'attendance' => [self::ATTENDANCE_COLUMNS, []],
            default => abort(404),
        };
    }

    private function employee(array $row, int $line, array &$errors): ?AttendanceEmployee
    {
        $code = trim((string) ($row['employee_code'] ?? ''));
        if ($code === '') { $errors[] = "Row {$line}: employee_code is empty."; return null; }
        $employee = AttendanceEmployee::where('employee_code', $code)->first();
        if (! $employee) $errors[] = "Row {$line}: no employee with code \"{$code}\" (check Employees for the exact code).";

        return $employee;
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
            $h = strtolower(trim(preg_replace('/[\s\-\.]+/', '_', trim($h)), '_'));

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

    private function summary(int $created = 0, int $updated = 0, int $kept = 0, array $errors = [], array $notes = [], int $rows = 0): array
    {
        return ['rows' => $rows, 'created' => $created, 'updated' => $updated, 'kept' => $kept, 'skipped' => count($errors), 'errors' => array_slice($errors, 0, 100), 'notes' => array_slice($notes, 0, 50), 'more_errors' => max(0, count($errors) - 100)];
    }
}
