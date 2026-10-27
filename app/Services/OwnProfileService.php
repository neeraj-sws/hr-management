<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\User;

/**
 * The details an employee may change about themself (website My profile and the app's Settings):
 * phone, date of birth, birthday sharing, address and emergency contact. Everything else is HR's.
 */
class OwnProfileService
{
    private const PHONE = ['nullable', 'string', 'max:40', 'regex:/^[0-9+\-\s()]*$/'];

    /** Validation rules, keyed by the field names the API uses. */
    public static function rules(): array
    {
        return [
            'phone' => self::PHONE,
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'share_birthday' => ['boolean'],
            'address' => ['nullable', 'string', 'max:500'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:60'],
            'emergency_contact_phone' => self::PHONE,
        ];
    }

    public static function messages(): array
    {
        return ['phone.regex' => 'Use only numbers, spaces, + - ( ).', 'emergency_contact_phone.regex' => 'Use only numbers, spaces, + - ( ).', 'date_of_birth.before' => 'The date of birth must be in the past.'];
    }

    public function save(AttendanceEmployee $employee, array $data, User $by): void
    {
        $clean = fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
        $employee->update([
            'phone' => $clean($data['phone'] ?? null),
            'date_of_birth' => $clean($data['date_of_birth'] ?? null),
            'share_birthday' => (bool) ($data['share_birthday'] ?? true),
            'address' => $clean($data['address'] ?? null),
            'emergency_contact_name' => $clean($data['emergency_contact_name'] ?? null),
            'emergency_contact_relation' => $clean($data['emergency_contact_relation'] ?? null),
            'emergency_contact_phone' => $clean($data['emergency_contact_phone'] ?? null),
        ]);
        app(AuditLogger::class)->record('profile.contact_updated', $employee);
    }

    /** What the app's Settings screen shows: own details (editable) and the ones only HR changes. */
    public function forApp(User $user): array
    {
        $employee = $user->employeeProfile?->load(['department', 'manager']);
        $twoFactor = app(TwoFactorService::class);
        $attendance = app(AttendanceService::class);

        return [
            'editable' => $employee ? [
                'phone' => (string) $employee->phone,
                'date_of_birth' => $employee->date_of_birth?->toDateString(),
                'share_birthday' => (bool) ($employee->share_birthday ?? true),
                'address' => (string) $employee->address,
                'emergency_contact_name' => (string) $employee->emergency_contact_name,
                'emergency_contact_relation' => (string) $employee->emergency_contact_relation,
                'emergency_contact_phone' => (string) $employee->emergency_contact_phone,
            ] : null,
            'hr_only' => $employee ? array_values(array_filter([
                ['label' => 'Name', 'value' => $employee->name],
                ['label' => 'Employee ID', 'value' => $employee->employee_code],
                ['label' => 'E-mail', 'value' => $employee->email ?: $user->email],
                ['label' => 'Designation', 'value' => $employee->designation],
                ['label' => 'Department', 'value' => $employee->department?->name],
                ['label' => 'Manager', 'value' => $employee->manager?->name],
                ['label' => 'Joined', 'value' => $employee->joined_at?->format('d M Y')],
                ['label' => 'Employment', 'value' => EmployeeService::EMPLOYMENT_TYPES[$employee->employment_type] ?? 'Full time'],
                ['label' => 'Work hours', 'value' => ($employee->work_start ? substr($employee->work_start, 0, 5).' – '.substr((string) $employee->work_end, 0, 5).' · ' : '').rtrim(rtrim(number_format((float) $attendance->dailyHours($employee), 2), '0'), '.').' h/day'],
            ], fn ($row) => filled($row['value']))) : [],
            'two_factor' => [
                'on' => (bool) $user->two_factor_enabled,
                'required' => $twoFactor->requiredFor($user) && ! $user->two_factor_enabled,
                'email' => $twoFactor->maskedEmail($user),
                'mail_available' => \App\Notifications\Channels\SafeMailChannel::enabled(),
                // Company setting: the code is asked in the desktop and mobile apps too, not only on the website.
                'apps_too' => (bool) ($twoFactor->settings()['apps'] ?? false),
            ],
        ];
    }
}
