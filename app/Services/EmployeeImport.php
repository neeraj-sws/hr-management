<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Employees from a CSV / Excel sheet (People > Employees > Import). A row whose employee code exists
 * updates that person (only the filled cells); a new code adds the person with a login. Each row is
 * saved on its own, so one bad row never stops the others; problems are listed by row number.
 */
class EmployeeImport
{
    public const COLUMNS = ['employee_code', 'name', 'email', 'designation', 'phone', 'department', 'manager_code', 'joined_at', 'employment_type', 'work_start', 'work_end', 'standard_daily_hours', 'monthly_salary'];

    public function __construct(private readonly ImportService $reader, private readonly EmployeeService $employees) {}

    /** @return array{created: int, updated: int, errors: list<string>, fatal: ?string} */
    public function import(UploadedFile $file, User $actor): array
    {
        abort_unless($actor->canAny(['employees.manage', 'hr.manage']), 403);
        [$rows, $fatal] = $this->reader->read($file, self::COLUMNS, ['employee_code', 'name']);
        $result = ['created' => 0, 'updated' => 0, 'errors' => [], 'fatal' => $fatal];
        if ($fatal) return $result;

        $departments = Department::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower(trim($name)) => $id]);
        foreach ($rows as [$line, $row]) {
            try {
                $data = $this->clean($row, $departments);
                $existing = AttendanceEmployee::with('user')->where('employee_code', $data['employee_code'])->first();
                if ($existing) {
                    $this->employees->update($existing, array_filter($data, fn ($v) => $v !== null && $v !== ''), $actor);
                    $result['updated']++;
                } else {
                    if (empty($data['email'])) throw new \InvalidArgumentException('email is needed for a new employee');
                    if (User::where('email', $data['email'])->exists()) throw new \InvalidArgumentException("the email {$data['email']} already has a login");
                    // A new login gets a random password: the person uses "Forgot password", or an admin resets it.
                    $this->employees->create([...$data, 'password' => Str::password(20), 'employment_type' => $data['employment_type'] ?? 'full_time'], $actor);
                    $result['created']++;
                }
            } catch (\Throwable $e) {
                $message = $e instanceof \Illuminate\Validation\ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
                if (count($result['errors']) < 50) $result['errors'][] = "Row {$line}: {$message}";
            }
        }
        app(AuditLogger::class)->record('employees.imported', null, ['created' => $result['created'], 'updated' => $result['updated'], 'errors' => count($result['errors'])]);

        return $result;
    }

    private function clean(array $row, $departments): array
    {
        $code = trim($row['employee_code'] ?? '');
        if ($code === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $code)) throw new \InvalidArgumentException('employee_code must be letters, numbers, - or _');
        $email = trim($row['email'] ?? '');
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException("\"{$email}\" is not an e-mail address");

        $department = null;
        if (($name = trim($row['department'] ?? '')) !== '') {
            $department = $departments[mb_strtolower($name)] ?? throw new \InvalidArgumentException("department \"{$name}\" does not exist (add it under People > Departments first)");
        }
        $manager = null;
        if (($managerCode = trim($row['manager_code'] ?? '')) !== '') {
            $manager = AttendanceEmployee::where('employee_code', $managerCode)->value('id') ?? throw new \InvalidArgumentException("manager \"{$managerCode}\" is not an employee code");
        }
        $type = null;
        if (($t = trim($row['employment_type'] ?? '')) !== '') {
            $key = str_replace([' ', '-'], '_', mb_strtolower($t));
            $type = array_key_exists($key, EmployeeService::EMPLOYMENT_TYPES) ? $key
                : (collect(EmployeeService::EMPLOYMENT_TYPES)->search(fn ($label) => mb_strtolower($label) === mb_strtolower($t)) ?: throw new \InvalidArgumentException("employment_type \"{$t}\" is not known"));
        }

        return [
            'employee_code' => $code,
            'name' => trim($row['name'] ?? '') ?: throw new \InvalidArgumentException('name is empty'),
            'email' => $email ?: null,
            'designation' => trim($row['designation'] ?? '') ?: null,
            'phone' => trim($row['phone'] ?? '') ?: null,
            'department_id' => $department,
            'manager_id' => $manager,
            'joined_at' => $this->date($row['joined_at'] ?? ''),
            'employment_type' => $type,
            'work_start' => $this->time($row['work_start'] ?? '', 'work_start'),
            'work_end' => $this->time($row['work_end'] ?? '', 'work_end'),
            'daily_work_hours' => $this->number($row['standard_daily_hours'] ?? '', 'daily hours', 24),
            'monthly_salary' => $this->number($row['monthly_salary'] ?? '', 'monthly_salary', 100000000),
        ];
    }

    private function date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        if (is_numeric($value)) return Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString(); // Excel serial date
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'Y/m/d'] as $format) {
            $date = rescue(fn () => Carbon::createFromFormat('!'.$format, $value), null, false);
            if ($date && $date->format($format) === $value) return $date->toDateString();
        }
        throw new \InvalidArgumentException("joined_at \"{$value}\" is not a date (use 2026-10-24 or 24-10-2026)");
    }

    private function time(string $value, string $column): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        if (preg_match('/^(\d{1,2}):(\d{2})/', $value, $m) && (int) $m[1] < 24 && (int) $m[2] < 60) return sprintf('%02d:%02d', $m[1], $m[2]);
        throw new \InvalidArgumentException("{$column} \"{$value}\" is not a time (use 09:30)");
    }

    private function number(string $value, string $column, float $max): ?float
    {
        $value = str_replace(',', '', trim($value));
        if ($value === '') return null;
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > $max) throw new \InvalidArgumentException("{$column} \"{$value}\" is not a valid number");

        return (float) $value;
    }
}
