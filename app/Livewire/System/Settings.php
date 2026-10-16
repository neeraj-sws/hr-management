<?php

namespace App\Livewire\System;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\LeaveType;
use App\Models\OfficeLocation;
use App\Services\AuditLogger;
use App\Services\PeopleOperationsService;
use App\Services\PolicyConfigurationService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Employer configuration stored as effective-dated policy rules. Nothing here
 * ships with statutory or legal rates: the employer supplies them.
 */
class Settings extends Component
{
    use InteractsWithHr;

    #[Url] public string $tab = 'leave';

    // Leave types
    public ?int $editingTypeId = null;
    public bool $showTypeForm = false;
    public array $type = [];

    // Policies
    public array $workdays = [1, 2, 3, 4, 5];
    public string $holidays = '';
    public string $newHoliday = '';
    public string $newHolidayName = '';
    public array $holidayNames = [];
    /** @var array<string, array{category: string, paid: bool}> date => holiday type */
    public array $holidayTypes = [];
    public string $newHolidayCategory = 'public';
    public bool $newHolidayPaid = true;
    public string $leaveYearStart = '4';
    public string $idleMinutes = '10';

    // Weekly offs: nth-weekday offs (e.g. 3rd Saturday), temporary shifts, and per-employee patterns.
    /** @var array<string, array<int, string>> weekday => occurrences that are off */
    public array $offWeeks = [];
    /** @var array<int, array{from: string, to: string, note: string}> company-wide temporary adjustments */
    public array $shifts = [];
    public string $shiftFrom = '';
    public string $shiftTo = '';
    public string $shiftNote = '';
    public string $shiftScope = 'company';
    public ?int $shiftEmployeeId = null;
    public ?int $patternEmployeeId = null;
    public array $employeeOffWeeks = [];
    public array $employeeDays = [];

    // Google Sheets
    public bool $sheetsEnabled = false;
    public string $sheetsUrl = '';
    public string $sheetsSecret = '';
    public array $sheetsReports = ['attendance'];
    public string $sheetsFrom = '';
    public string $sheetsTo = '';

    // E-mail
    public bool $emailEnabled = true;
    public string $testEmail = '';

    // Offboarding correction window
    public string $offboardingWindowHours = '72';
    public bool $applyHoursToAll = false;

    /** Company salary template: earnings as % of monthly salary, deductions as fixed monthly amounts. */
    public array $structureRows = [];

    // Screen capture (off by default)
    public bool $captureEnabled = false;
    public string $captureInterval = '10';
    public bool $captureActiveOnly = true;
    public string $captureWindowStart = '';
    public string $captureWindowEnd = '';
    public string $captureRetention = '30';

    // Office locations
    public bool $requireLocation = false;
    public bool $requireLocationWeb = false;
    public bool $selfieWeb = false;
    public bool $selfieMobile = false;
    public bool $alertsEnabled = true;
    public string $absentAfter = '11:00';
    public bool $showOfficeForm = false;
    public ?int $officeId = null;
    public string $officeName = '';
    public string $officeLat = '';
    public string $officeLng = '';
    public string $officeRadius = '200';
    public bool $officeActive = true;
    public string $startTime = '';
    public string $endTime = '';
    public string $dailyHours = '8';
    public string $graceMinutes = '0';
    public string $halfDayHours = '4';
    public string $otMultiplier = '';
    /** Overtime switched off company-wide: no requests, and payroll pays no overtime. */
    public bool $otEnabled = true;
    public string $otMaxPerDay = '';
    public string $otHolidayMultiplier = '';
    public string $otMaxPerMonthHours = '';
    public string $workingDaysPerMonth = '22';
    public bool $attendanceProration = true;
    public bool $includePaidLeave = true;
    public bool $shortHoursDeduction = false;
    public string $shortHoursGrace = '0';
    public string $advanceLeaveMax = '5';
    public bool $encashmentEnabled = false;
    public string $encashmentRate = '0';
    public string $statutoryJson = '';

    public function mount(PolicyConfigurationService $policies): void
    {
        $today = today()->toDateString();
        $calendar = $policies->forEmployee(null, 'calendar.working_days', $today, ['days' => [1, 2, 3, 4, 5], 'holidays' => []]);
        $hours = $policies->forEmployee(null, 'work.hours', $today, []);
        $rules = $policies->forEmployee(null, 'attendance.rules', $today, []);
        $ot = $policies->forEmployee(null, 'overtime.multiplier', $today, []);
        $payroll = $policies->forEmployee(null, 'payroll.calculation', $today, []);
        $advance = $policies->forEmployee(null, 'leave.advance_limit', $today, ['max_days' => 5]);
        $settlement = $policies->forEmployee(null, 'leave.final_settlement', $today, []);
        $statutory = $policies->forEmployee(null, 'payroll.statutory', $today, ['components' => []]);
        $idle = $policies->forEmployee(null, 'attendance.idle', $today, ['away_prompt_minutes' => 10]);
        $location = $policies->forEmployee(null, 'attendance.location', $today, []);
        $capture = $policies->forEmployee(null, 'monitoring.screenshots', $today, []);
        $this->structureRows = collect($policies->forEmployee(null, 'payroll.salary_structure', $today, ['components' => []])['components'] ?? [])
            ->map(fn ($c) => ['name' => (string) ($c['name'] ?? ''), 'type' => (string) ($c['type'] ?? 'earning'), 'value' => (string) ($c['type'] === 'deduction' ? ($c['amount'] ?? '') : ($c['percent'] ?? ''))])->all();

        $email = $policies->forEmployee(null, 'notifications.email', $today, ['enabled' => true]);
        $selfie = $policies->forEmployee(null, 'attendance.selfie', $today, []);
        $alerts = $policies->forEmployee(null, 'attendance.alerts', $today, \App\Services\AttendanceAlertService::DEFAULTS);
        $offboarding = $policies->forEmployee(null, 'lifecycle.offboarding', $today, ['correction_window_hours' => 72]);
        $sheets = app(\App\Services\GoogleSheetsSync::class)->settings();
        $this->fill(['sheetsEnabled' => $sheets['enabled'], 'sheetsUrl' => $sheets['url'], 'sheetsReports' => $sheets['reports'] ?: ['attendance'], 'sheetsFrom' => today()->subDay()->toDateString(), 'sheetsTo' => $today]);
        $this->fill([
            'offWeeks' => \App\Support\WorkCalendar::offWeeksForForm((array) ($calendar['off_weeks'] ?? [])),
            'shifts' => array_values((array) ($calendar['shifts'] ?? [])),
            'emailEnabled' => (bool) ($email['enabled'] ?? true),
            'testEmail' => (string) $this->user()->email,
            'offboardingWindowHours' => (string) ($offboarding['correction_window_hours'] ?? 72),
            'workdays' => array_map('intval', $calendar['days'] ?? [1, 2, 3, 4, 5]),
            'holidays' => implode("\n", $calendar['holidays'] ?? []),
            'holidayNames' => (array) ($calendar['holiday_names'] ?? []),
            'holidayTypes' => collect((array) ($calendar['holiday_types'] ?? []))->map(fn ($t) => \App\Support\WorkCalendar::holidayTypeOf(['holiday_types' => ['d' => $t]], 'd'))->all(),
            'leaveYearStart' => (string) \App\Support\LeaveYear::startMonth(),
            'idleMinutes' => (string) ($idle['away_prompt_minutes'] ?? 10),
            'requireLocation' => (bool) ($location['required_for_mobile'] ?? false),
            'requireLocationWeb' => (bool) ($location['required_for_web'] ?? false),
            'selfieWeb' => (bool) ($selfie['required_web'] ?? false), 'selfieMobile' => (bool) ($selfie['required_mobile'] ?? false),
            'alertsEnabled' => (bool) ($alerts['enabled'] ?? true), 'absentAfter' => (string) ($alerts['absent_after'] ?? '11:00'),
            'captureEnabled' => (bool) ($capture['enabled'] ?? false), 'captureInterval' => (string) ($capture['interval_minutes'] ?? 10), 'captureActiveOnly' => (bool) ($capture['active_only'] ?? true),
            'captureWindowStart' => (string) ($capture['window_start'] ?? ''), 'captureWindowEnd' => (string) ($capture['window_end'] ?? ''), 'captureRetention' => (string) ($capture['retention_days'] ?? 30),
            'startTime' => (string) ($hours['start_time'] ?? ''), 'endTime' => (string) ($hours['end_time'] ?? ''),
            'dailyHours' => (string) ($hours['standard_daily_hours'] ?? 8),
            'graceMinutes' => (string) ($rules['grace_minutes'] ?? 0), 'halfDayHours' => (string) ($rules['half_day_hours'] ?? 4),
            'otEnabled' => (bool) ($ot['enabled'] ?? true), 'otMultiplier' => (string) ($ot['multiplier'] ?? ''), 'otMaxPerDay' => (string) ($ot['max_minutes_per_day'] ?? ''), 'otHolidayMultiplier' => (string) ($ot['holiday_multiplier'] ?? ''), 'otMaxPerMonthHours' => isset($ot['max_minutes_per_month']) ? (string) round($ot['max_minutes_per_month'] / 60, 2) : '',
            'workingDaysPerMonth' => (string) ($payroll['working_days_per_month'] ?? 22),
            'attendanceProration' => (bool) ($payroll['attendance_proration'] ?? true), 'includePaidLeave' => (bool) ($payroll['include_paid_leave'] ?? true), 'shortHoursDeduction' => (bool) ($payroll['short_hours_deduction'] ?? false), 'shortHoursGrace' => (string) ($payroll['short_hours_grace'] ?? 0),
            'advanceLeaveMax' => (string) ($advance['max_days'] ?? 5),
            'encashmentEnabled' => (bool) ($settlement['leave_encashment_enabled'] ?? false), 'encashmentRate' => (string) ($settlement['rate_per_day'] ?? 0),
            'statutoryJson' => json_encode($statutory['components'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function authorizeSettings(): void
    {
        abort_unless($this->user()->can('policies.manage'), 403);
    }

    public function editType(?int $id = null): void
    {
        $this->authorizeSettings();
        $type = $id ? LeaveType::findOrFail($id) : null;
        $this->editingTypeId = $type?->id;
        $this->type = ['name' => $type?->name ?? '', 'annual_allowance' => (string) ($type?->annual_allowance ?? 0), 'monthly_accrual' => (string) ($type?->monthly_accrual ?? 0), 'carry_forward' => (bool) $type?->carry_forward, 'carry_forward_limit' => (string) ($type?->carry_forward_limit ?? 0), 'carry_forward_expiry_months' => (string) ($type?->carry_forward_expiry_months ?? ''), 'allow_advance' => (bool) $type?->allow_advance, 'paid' => $type ? (bool) $type->paid : true, 'is_active' => $type ? (bool) $type->is_active : true];
        $this->resetValidation();
        $this->showTypeForm = true;
    }

    public function saveType(AuditLogger $audit): void
    {
        $this->authorizeSettings();
        $this->validate([
            'type.name' => ['required', 'string', 'max:100', Rule::unique('leave_types', 'name')->ignore($this->editingTypeId)],
            'type.annual_allowance' => ['required', 'numeric', 'min:0', 'max:365'],
            'type.monthly_accrual' => ['required', 'numeric', 'min:0', 'max:31'],
            'type.carry_forward_limit' => ['required', 'numeric', 'min:0', 'max:365'],
            'type.carry_forward_expiry_months' => ['nullable', 'integer', 'min:1', 'max:24'],
        ], [], ['type.name' => 'name', 'type.annual_allowance' => 'annual allowance', 'type.monthly_accrual' => 'monthly accrual', 'type.carry_forward_limit' => 'carry-forward limit', 'type.carry_forward_expiry_months' => 'carry-forward expiry']);
        $data = [...$this->type, 'carry_forward_expiry_months' => $this->type['carry_forward_expiry_months'] === '' ? null : (int) $this->type['carry_forward_expiry_months']];
        $type = LeaveType::updateOrCreate(['id' => $this->editingTypeId], $data);
        $audit->record($this->editingTypeId ? 'leave_type.updated' : 'leave_type.created', $type);
        $this->showTypeForm = false;
        $this->toast('Leave type saved.');
    }

    /** Holidays as a sorted list, for the date-picker list on the calendar tab. */
    public function holidayList(): array
    {
        return collect(preg_split('/[\s,]+/', trim($this->holidays)))->filter()->unique()->sort()->values()->all();
    }

    public function addHoliday(): void
    {
        $this->authorizeSettings();
        $this->validate(['newHoliday' => ['required', 'date_format:Y-m-d']], ['newHoliday.required' => 'Pick a date to add.'], ['newHoliday' => 'holiday']);
        $this->validate(['newHolidayName' => ['nullable', 'string', 'max:80']], [], ['newHolidayName' => 'holiday name']);
        $this->holidays = implode("\n", collect($this->holidayList())->push($this->newHoliday)->unique()->sort()->values()->all());
        $this->validate(['newHolidayCategory' => ['required', Rule::in(array_keys(\App\Support\WorkCalendar::HOLIDAY_CATEGORIES))]], [], ['newHolidayCategory' => 'holiday type']);
        if (trim($this->newHolidayName) !== '') $this->holidayNames[$this->newHoliday] = trim($this->newHolidayName);
        $this->holidayTypes[$this->newHoliday] = ['category' => $this->newHolidayCategory, 'paid' => $this->newHolidayPaid];
        $this->newHoliday = '';
        $this->newHolidayName = '';
        $this->newHolidayCategory = 'public';
        $this->newHolidayPaid = true;
    }

    public function removeHoliday(string $date): void
    {
        $this->authorizeSettings();
        $this->holidays = implode("\n", collect($this->holidayList())->reject(fn ($d) => $d === $date)->values()->all());
        unset($this->holidayNames[$date], $this->holidayTypes[$date]);
    }

    /** Flips a listed holiday between paid and unpaid (kept after "Save calendar"). */
    public function toggleHolidayPaid(string $date): void
    {
        $this->authorizeSettings();
        $type = \App\Support\WorkCalendar::holidayTypeOf(['holiday_types' => $this->holidayTypes], $date);
        $this->holidayTypes[$date] = ['category' => $type['category'], 'paid' => ! $type['paid']];
    }

    public function saveLeaveYear(PeopleOperationsService $people): void
    {
        $this->authorizeSettings();
        $this->validate(['leaveYearStart' => ['required', 'integer', 'between:1,12']], [], ['leaveYearStart' => 'leave year start']);
        $people->setPolicy('leave.year', 'leave', 'Leave year', ['start_month' => (int) $this->leaveYearStart], null, auth()->id(), today()->toDateString());
        $this->toast('Leave year saved: '.\Illuminate\Support\Carbon::create(2000, (int) $this->leaveYearStart, 1)->format('F').' to '.\Illuminate\Support\Carbon::create(2000, (int) $this->leaveYearStart, 1)->subMonth()->format('F').'.');
    }

    public function saveCalendar(PeopleOperationsService $people): void
    {
        $this->authorizeSettings();
        $this->validate(['workdays' => ['required', 'array', 'min:1'], 'workdays.*' => ['integer', 'between:1,7'], 'holidays' => ['nullable', 'string', 'max:5000']], ['workdays.required' => 'Select at least one working day.']);
        $holidays = collect(preg_split('/[\s,]+/', trim($this->holidays)))->filter()->unique()->values();
        $invalid = $holidays->reject(fn ($date) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date));
        if ($invalid->isNotEmpty()) {
            $this->addError('holidays', 'Use YYYY-MM-DD dates. Invalid: '.$invalid->join(', '));

            return;
        }
        $calendar = \App\Support\WorkCalendar::normalise(['days' => array_map('intval', $this->workdays), 'holidays' => $holidays->sort()->values()->all(), 'holiday_names' => array_intersect_key(array_map(fn ($name) => mb_substr(trim((string) $name), 0, 80), $this->holidayNames), array_flip($holidays->all())), 'holiday_types' => collect($holidays)->mapWithKeys(fn ($date) => [$date => \App\Support\WorkCalendar::holidayTypeOf(['holiday_types' => $this->holidayTypes], $date)])->all(), 'off_weeks' => $this->offWeeks, 'shifts' => $this->shifts]);
        $before = app(PolicyConfigurationService::class)->forEmployee(null, 'calendar.working_days', today()->toDateString(), ['days' => [1, 2, 3, 4, 5], 'holidays' => []]);
        $people->setPolicy('calendar.working_days', 'calendar', 'Working calendar', $calendar, null, auth()->id(), today()->toDateString());
        $this->offWeeks = \App\Support\WorkCalendar::offWeeksForForm($calendar['off_weeks']);
        $this->shifts = $calendar['shifts'];
        // Everyone hears about a change of weekly offs (in the app; Holidays page shows the dates).
        $told = app(\App\Services\CompanyService::class)->announceWeeklyOffChange($before, $calendar, $this->user());
        $this->toast('Working calendar saved.'.($told ? " {$told} employee(s) were notified about the weekly off change." : ''));
    }

    /**
     * Moves a weekly off for one week: "from" (normally off) becomes a working
     * day and "to" becomes the off day. Company-wide adjustments are saved with
     * the calendar; an employee adjustment is saved straight away on that person.
     */
    public function addShift(): void
    {
        $this->authorizeSettings();
        $this->validate([
            'shiftFrom' => ['required', 'date_format:Y-m-d'],
            'shiftTo' => ['required', 'date_format:Y-m-d', 'different:shiftFrom'],
            'shiftNote' => ['nullable', 'string', 'max:120'],
            'shiftScope' => ['required', 'in:company,employee'],
            'shiftEmployeeId' => ['required_if:shiftScope,employee', 'nullable', 'exists:attendance_employees,id'],
        ], ['shiftTo.different' => 'The new off day must be a different date.', 'shiftEmployeeId.required_if' => 'Choose the employee.'], ['shiftFrom' => 'usual off day', 'shiftTo' => 'new off day']);
        $shift = ['from' => $this->shiftFrom, 'to' => $this->shiftTo, 'note' => trim($this->shiftNote)];
        if ($this->shiftScope === 'employee') {
            $this->updateEmployeeCalendar((int) $this->shiftEmployeeId, fn (array $config) => [...$config, 'shifts' => [...(array) ($config['shifts'] ?? []), $shift]]);
            $this->toast('Weekly off moved for this employee.');
        } else {
            $this->shifts = \App\Support\WorkCalendar::normalise(['shifts' => [...$this->shifts, $shift]])['shifts'];
            $this->toast('Added. Press "Save calendar" to apply it company-wide.');
        }
        $this->reset('shiftFrom', 'shiftTo', 'shiftNote');
    }

    public function removeShift(int $index): void
    {
        $this->authorizeSettings();
        unset($this->shifts[$index]);
        $this->shifts = array_values($this->shifts);
    }

    public function editEmployeePattern(): void
    {
        $this->authorizeSettings();
        $employee = \App\Models\AttendanceEmployee::find($this->patternEmployeeId);
        if (! $employee) { $this->employeeOffWeeks = []; $this->employeeDays = []; return; }
        $effective = app(PolicyConfigurationService::class)->forEmployee($employee, 'calendar.working_days', today()->toDateString(), ['days' => [1, 2, 3, 4, 5], 'holidays' => []]);
        $this->employeeDays = array_map('strval', $effective['days'] ?? [1, 2, 3, 4, 5]);
        $this->employeeOffWeeks = \App\Support\WorkCalendar::offWeeksForForm((array) ($effective['off_weeks'] ?? []));
    }

    public function updatedPatternEmployeeId(): void
    {
        $this->editEmployeePattern();
    }

    /** Saves this employee's own weekly-off pattern (e.g. 2nd and 3rd Saturday off). */
    public function saveEmployeePattern(): void
    {
        $this->authorizeSettings();
        $this->validate(['patternEmployeeId' => ['required', 'exists:attendance_employees,id'], 'employeeDays' => ['required', 'array', 'min:1'], 'employeeDays.*' => ['integer', 'between:1,7']], ['employeeDays.required' => 'Select at least one working day.'], ['patternEmployeeId' => 'employee']);
        $normalised = \App\Support\WorkCalendar::normalise(['off_weeks' => $this->employeeOffWeeks]);
        $this->updateEmployeeCalendar((int) $this->patternEmployeeId, fn (array $config) => [...$config, 'days' => array_values(array_unique(array_map('intval', $this->employeeDays))), 'off_weeks' => $normalised['off_weeks']]);
        $this->toast('Weekly offs saved for this employee.');
    }

    /** Removes an employee's own pattern and adjustments: they follow the company calendar again. */
    public function clearEmployeeCalendar(int $employeeId, AuditLogger $audit): void
    {
        $this->authorizeSettings();
        $removed = \App\Models\EmployeePolicyOverride::where('employee_id', $employeeId)->where('rule_key', 'calendar.working_days')->delete();
        $audit->record('policy.employee_calendar_cleared', \App\Models\AttendanceEmployee::find($employeeId), ['rows' => $removed]);
        if ($this->patternEmployeeId === $employeeId) $this->editEmployeePattern();
        $this->toast('The employee follows the company calendar again.');
    }

    /** One standing calendar override per employee; only the keys it sets replace the company calendar. */
    private function updateEmployeeCalendar(int $employeeId, \Closure $change): void
    {
        $employee = \App\Models\AttendanceEmployee::find($employeeId);
        $before = $employee ? app(PolicyConfigurationService::class)->forEmployee($employee, 'calendar.working_days', today()->toDateString(), ['days' => [1, 2, 3, 4, 5], 'holidays' => []]) : [];
        $row = \App\Models\EmployeePolicyOverride::where('employee_id', $employeeId)->where('rule_key', 'calendar.working_days')->whereNull('effective_from')->first();
        $config = \App\Support\WorkCalendar::normalise($change((array) ($row?->configuration ?? [])));
        if (empty($config['off_weeks'])) unset($config['off_weeks']);
        if (empty($config['shifts'])) unset($config['shifts']);
        if ($row) {
            $row->update(['configuration' => $config, 'configured_by' => auth()->id()]);
        } else {
            \App\Models\EmployeePolicyOverride::create(['employee_id' => $employeeId, 'rule_key' => 'calendar.working_days', 'effective_from' => null, 'configuration' => $config, 'configured_by' => auth()->id()]);
        }
        app(AuditLogger::class)->record('policy.employee_calendar_updated', $employee, $config);
        // The employee is told about their own weekly-off change.
        if ($employee) app(\App\Services\CompanyService::class)->announceWeeklyOffChange($before, app(PolicyConfigurationService::class)->forEmployee($employee, 'calendar.working_days', today()->toDateString(), ['days' => [1, 2, 3, 4, 5], 'holidays' => []]), $this->user(), $employee);
    }

    public function saveSheets(\App\Services\GoogleSheetsSync $sheets): void
    {
        $this->authorizeSettings();
        $this->validate([
            'sheetsUrl' => [$this->sheetsEnabled ? 'required' : 'nullable', 'url', 'starts_with:https://', 'max:500'],
            'sheetsSecret' => ['nullable', 'string', 'min:12', 'max:200'],
            'sheetsReports' => ['array', $this->sheetsEnabled ? 'min:1' : 'min:0'], 'sheetsReports.*' => [Rule::in(array_keys(\App\Services\GoogleSheetsSync::REPORTS))],
        ], ['sheetsUrl.starts_with' => 'Use the https:// web-app URL from Apps Script (Deploy → Web app).'], ['sheetsUrl' => 'web-app URL', 'sheetsSecret' => 'shared secret', 'sheetsReports' => 'reports']);
        if ($this->sheetsEnabled && $this->sheetsSecret === '' && ! $sheets->settings()['secret']) {
            $this->addError('sheetsSecret', 'Enter the shared secret you put in the Apps Script.');

            return;
        }
        $sheets->configure($this->sheetsEnabled, $this->sheetsUrl, $this->sheetsSecret, $this->sheetsReports, $this->user());
        $this->sheetsSecret = '';
        $this->toast($this->sheetsEnabled ? 'Google Sheets sync saved. It runs every 30 minutes.' : 'Google Sheets sync turned off.');
    }

    public function syncSheetsNow(\App\Services\GoogleSheetsSync $sheets): void
    {
        $this->authorizeSettings();
        $this->validate(['sheetsFrom' => ['required', 'date'], 'sheetsTo' => ['required', 'date', 'after_or_equal:sheetsFrom']], [], ['sheetsFrom' => 'from', 'sheetsTo' => 'to']);
        if (\Illuminate\Support\Carbon::parse($this->sheetsFrom)->diffInDays(\Illuminate\Support\Carbon::parse($this->sheetsTo)) > 62) {
            $this->addError('sheetsTo', 'Send at most two months at a time.');

            return;
        }
        $result = $sheets->sync(\Illuminate\Support\Carbon::parse($this->sheetsFrom), \Illuminate\Support\Carbon::parse($this->sheetsTo), force: true);
        $this->toast($result['message'], $result['ok'] ? 'success' : 'error');
    }

    public function saveEmail(PeopleOperationsService $people): void
    {
        $this->authorizeSettings();
        $people->setPolicy('notifications.email', 'notifications', 'E-mail notifications', ['enabled' => $this->emailEnabled], null, auth()->id(), today()->toDateString());
        $this->toast($this->emailEnabled ? 'E-mail notifications are on.' : 'E-mail notifications are off; people still get in-app notifications.');
    }

    /** Sends a test e-mail right now (not queued) and shows the exact error if delivery fails. */
    public function sendTestEmail(AuditLogger $audit): void
    {
        $this->authorizeSettings();
        $this->validate(['testEmail' => ['required', 'email']], [], ['testEmail' => 'test address']);
        try {
            \Illuminate\Support\Facades\Mail::raw('This is a test e-mail from '.config('app.name').' sent at '.now()->format('d M Y, h:i A').'. If you can read this, e-mail delivery works.', fn ($m) => $m->to($this->testEmail)->subject(config('app.name').': test e-mail'));
            $audit->record('notification.email_test_sent', null, ['to' => $this->testEmail, 'mailer' => config('mail.default')]);
            $this->toast('Test e-mail sent to '.$this->testEmail.'. Check the inbox (and spam).');
        } catch (\Throwable $error) {
            \App\Notifications\Channels\SafeMailChannel::recordFailure($error, $this->testEmail, 'Settings test e-mail');
            $this->addError('testEmail', 'Sending failed: '.\Illuminate\Support\Str::limit($error->getMessage(), 300));
        }
    }

    public function saveOffboarding(PeopleOperationsService $people): void
    {
        $this->authorizeSettings();
        $this->validate(['offboardingWindowHours' => ['required', 'integer', 'min:0', 'max:720']], [], ['offboardingWindowHours' => 'correction window']);
        $people->setPolicy('lifecycle.offboarding', 'lifecycle', 'Offboarding correction window', ['correction_window_hours' => (int) $this->offboardingWindowHours], null, auth()->id(), today()->toDateString());
        $this->toast('Offboarding correction window saved.');
    }

    public function saveHours(PeopleOperationsService $people): void
    {
        $this->authorizeSettings();
        $this->validate(['startTime' => ['nullable', 'date_format:H:i'], 'endTime' => ['nullable', 'date_format:H:i'], 'dailyHours' => ['required', 'numeric', 'gt:0', 'max:24'], 'graceMinutes' => ['required', 'integer', 'min:0', 'max:240'], 'halfDayHours' => ['required', 'numeric', 'gt:0', 'max:24'], 'idleMinutes' => ['required', 'integer', 'min:0', 'max:240']], [], ['idleMinutes' => 'away minutes']);
        $people->setPolicy('work.hours', 'attendance', 'Work hours', array_filter(['start_time' => $this->startTime ?: null, 'end_time' => $this->endTime ?: null, 'standard_daily_hours' => (float) $this->dailyHours], fn ($v) => $v !== null), null, auth()->id(), today()->toDateString());
        // The company value is the default for new employees. Applying it to everyone updates each
        // active employee's own hours and current salary record, so Payroll and Attendance agree.
        if ($this->applyHoursToAll) {
            $updated = 0;
            foreach (\App\Models\AttendanceEmployee::where('is_active', true)->get() as $employee) {
                $employee->update(['daily_work_hours' => (float) $this->dailyHours]);
                $employee->salaryProfiles()->orderByDesc('effective_from')->orderByDesc('id')->first()?->update(['standard_daily_hours' => (float) $this->dailyHours]);
                $updated++;
            }
            app(AuditLogger::class)->record('employee.work_hours_applied_to_all', null, ['daily_hours' => (float) $this->dailyHours, 'employees' => $updated]);
            $this->applyHoursToAll = false;
        }
        $people->setPolicy('attendance.rules', 'attendance', 'Attendance rules', ['grace_minutes' => (int) $this->graceMinutes, 'half_day_hours' => (float) $this->halfDayHours], null, auth()->id(), today()->toDateString());
        $people->setPolicy('attendance.idle', 'attendance', 'Away-time prompt (desktop)', ['away_prompt_minutes' => (int) $this->idleMinutes], null, auth()->id(), today()->toDateString());
        $this->validate(['absentAfter' => ['required', 'date_format:H:i']], [], ['absentAfter' => 'absent alert time']);
        $people->setPolicy('attendance.selfie', 'attendance', 'Selfie at check-in/out', ['required_web' => $this->selfieWeb, 'required_mobile' => $this->selfieMobile], null, auth()->id(), today()->toDateString());
        $people->setPolicy('attendance.alerts', 'attendance', 'Late and absence alerts', ['enabled' => $this->alertsEnabled, 'absent_after' => $this->absentAfter], null, auth()->id(), today()->toDateString());
        $this->toast('Work hours and attendance rules saved.');
    }

    public function savePayroll(PeopleOperationsService $people): void
    {
        $this->authorizeSettings();
        $this->validate(['otMultiplier' => ['nullable', 'numeric', 'min:0', 'max:10'], 'otMaxPerDay' => ['nullable', 'integer', 'min:1', 'max:1440'], 'otHolidayMultiplier' => ['nullable', 'numeric', 'min:0', 'max:10'], 'otMaxPerMonthHours' => ['nullable', 'numeric', 'min:0.25', 'max:400'], 'workingDaysPerMonth' => ['required', 'integer', 'min:1', 'max:31'], 'shortHoursGrace' => ['required', 'numeric', 'min:0', 'max:200'], 'advanceLeaveMax' => ['required', 'numeric', 'min:0', 'max:60'], 'encashmentRate' => ['required', 'numeric', 'min:0']]);
        $ot = array_filter(['multiplier' => $this->otMultiplier === '' ? null : (float) $this->otMultiplier, 'max_minutes_per_day' => $this->otMaxPerDay === '' ? null : (int) $this->otMaxPerDay, 'holiday_multiplier' => $this->otHolidayMultiplier === '' ? null : (float) $this->otHolidayMultiplier, 'max_minutes_per_month' => $this->otMaxPerMonthHours === '' ? null : (int) round((float) $this->otMaxPerMonthHours * 60)], fn ($v) => $v !== null);
        $people->setPolicy('overtime.multiplier', 'overtime', 'Overtime', ['enabled' => $this->otEnabled, ...$ot], null, auth()->id(), today()->toDateString());
        $people->setPolicy('payroll.calculation', 'payroll', 'Payroll calculation', ['working_days_per_month' => (int) $this->workingDaysPerMonth, 'attendance_proration' => $this->attendanceProration, 'include_paid_leave' => $this->includePaidLeave, 'short_hours_deduction' => $this->shortHoursDeduction, 'short_hours_grace' => (float) $this->shortHoursGrace], null, auth()->id(), today()->toDateString());
        $people->setPolicy('leave.advance_limit', 'leave', 'Advance leave limit', ['max_days' => (float) $this->advanceLeaveMax], null, auth()->id(), today()->toDateString());
        $people->setPolicy('leave.final_settlement', 'leave', 'Final settlement leave encashment', ['leave_encashment_enabled' => $this->encashmentEnabled, 'rate_per_day' => (float) $this->encashmentRate], null, auth()->id(), today()->toDateString());
        $this->toast('Payroll, overtime and leave policies saved.');
    }

    public function saveStatutory(PeopleOperationsService $people): void
    {
        $this->authorizeSettings();
        $components = json_decode($this->statutoryJson ?: '[]', true);
        if (! is_array($components) || ! array_is_list($components)) {
            $this->addError('statutoryJson', 'Enter a JSON array of components (use [] for none).');

            return;
        }
        $people->setPolicy('payroll.statutory', 'payroll', 'Statutory components', ['components' => $components], null, auth()->id(), today()->toDateString());
        $this->toast('Statutory components saved.');
    }

    public function closeModal(): void
    {
        $this->showOfficeForm = false;
        $this->showTypeForm = false;
    }

    public function editOffice(?int $id = null): void
    {
        $this->authorizeSettings();
        $office = $id ? OfficeLocation::findOrFail($id) : null;
        $this->fill(['officeId' => $office?->id, 'officeName' => $office?->name ?? '', 'officeLat' => $office ? (string) $office->latitude : '', 'officeLng' => $office ? (string) $office->longitude : '', 'officeRadius' => (string) ($office?->radius_meters ?? 200), 'officeActive' => $office?->is_active ?? true]);
        $this->resetValidation();
        $this->showOfficeForm = true;
    }

    public function saveOffice(AuditLogger $audit): void
    {
        $this->authorizeSettings();
        $this->validate(['officeName' => ['required', 'string', 'max:120'], 'officeLat' => ['required', 'numeric', 'between:-90,90'], 'officeLng' => ['required', 'numeric', 'between:-180,180'], 'officeRadius' => ['required', 'integer', 'min:25', 'max:5000']], [], ['officeName' => 'name', 'officeLat' => 'latitude', 'officeLng' => 'longitude', 'officeRadius' => 'radius']);
        $office = OfficeLocation::updateOrCreate(['id' => $this->officeId], ['name' => $this->officeName, 'latitude' => $this->officeLat, 'longitude' => $this->officeLng, 'radius_meters' => (int) $this->officeRadius, 'is_active' => $this->officeActive]);
        $audit->record($this->officeId ? 'office_location.updated' : 'office_location.created', $office);
        $this->showOfficeForm = false;
        $this->toast('Office location saved.');
    }

    public function addStructureRow(string $type = 'earning'): void
    {
        $this->structureRows[] = ['name' => '', 'type' => $type, 'value' => ''];
    }

    public function removeStructureRow(int $index): void
    {
        unset($this->structureRows[$index]);
        $this->structureRows = array_values($this->structureRows);
    }

    public function saveStructure(PeopleOperationsService $people): void
    {
        $this->authorizeSettings();
        $this->validate(['structureRows.*.name' => ['required', 'string', 'max:60'], 'structureRows.*.type' => ['required', 'in:earning,deduction'], 'structureRows.*.value' => ['required', 'numeric', 'min:0']], [], ['structureRows.*.name' => 'component name', 'structureRows.*.value' => 'value']);
        $percent = collect($this->structureRows)->where('type', 'earning')->sum(fn ($r) => (float) $r['value']);
        if ($percent > 100.0001) { $this->addError('structureRows', 'Earning percentages add up to '.$percent.'%; they cannot exceed 100%.'); return; }
        $components = collect($this->structureRows)->map(fn ($r) => $r['type'] === 'earning' ? ['name' => trim($r['name']), 'type' => 'earning', 'percent' => (float) $r['value']] : ['name' => trim($r['name']), 'type' => 'deduction', 'amount' => (float) $r['value']])->values()->all();
        $people->setPolicy('payroll.salary_structure', 'payroll', 'Salary structure template', ['components' => $components], null, auth()->id(), today()->toDateString());
        $this->toast($percent < 100 && $percent > 0 ? 'Template saved. The remaining '.(100 - $percent).'% is paid as "Balance allowance".' : 'Salary structure template saved.');
    }

    public function toggleEmployeeCapture(int $employeeId, \App\Services\ScreenCaptureService $captures): void
    {
        $employee = \App\Models\AttendanceEmployee::findOrFail($employeeId);
        $on = ! $captures->policy($employee)['enabled'];
        $captures->setForEmployee($employee, $on, $this->user());
        $this->toast($on ? "Screen capture on for {$employee->name}." : "Screen capture off for {$employee->name}.");
    }

    public function saveCapturePolicy(PeopleOperationsService $people): void
    {
        abort_unless($this->user()->can('monitoring.manage'), 403);
        $this->validate(['captureInterval' => ['required', Rule::in(array_map('strval', \App\Services\ScreenCaptureService::INTERVALS))], 'captureWindowStart' => ['nullable', 'date_format:H:i', 'required_with:captureWindowEnd'], 'captureWindowEnd' => ['nullable', 'date_format:H:i', 'required_with:captureWindowStart'], 'captureRetention' => ['required', 'integer', 'min:1', 'max:365']], [], ['captureInterval' => 'interval', 'captureWindowStart' => 'window start', 'captureWindowEnd' => 'window end', 'captureRetention' => 'retention']);
        $people->setPolicy('monitoring.screenshots', 'monitoring', 'Screen capture', array_filter(['enabled' => $this->captureEnabled, 'interval_minutes' => (int) $this->captureInterval, 'active_only' => $this->captureActiveOnly, 'window_start' => $this->captureWindowStart ?: null, 'window_end' => $this->captureWindowEnd ?: null, 'retention_days' => (int) $this->captureRetention], fn ($v) => $v !== null), null, auth()->id(), today()->toDateString());
        $this->toast($this->captureEnabled ? 'Screen capture is on for all employees.' : 'Saved. Capture runs only for employees you switch on below.');
    }

    public function saveLocationPolicy(PeopleOperationsService $people): void
    {
        $this->authorizeSettings();
        if (($this->requireLocation || $this->requireLocationWeb) && ! OfficeLocation::where('is_active', true)->exists()) {
            $this->requireLocation = $this->requireLocationWeb = false;
            $this->addError('requireLocation', 'Add at least one active office before requiring location.');

            return;
        }
        $people->setPolicy('attendance.location', 'attendance', 'Check-in location', ['required_for_mobile' => $this->requireLocation, 'required_for_web' => $this->requireLocationWeb], null, auth()->id(), today()->toDateString());
        $this->toast('Location rules saved: mobile '.($this->requireLocation ? 'must be at an office' : 'no location needed').', website '.($this->requireLocationWeb ? 'must be at an office' : 'no location needed').'.');
    }

    public function render()
    {
        $canCapture = $this->user()->can('monitoring.manage');
        $captureEmployees = $canCapture && $this->tab === 'capture'
            ? \App\Models\AttendanceEmployee::with('department')->where('is_active', true)->orderBy('name')->get()->map(fn ($e) => ['employee' => $e, 'on' => app(\App\Services\ScreenCaptureService::class)->policy($e)['enabled']])
            : collect();

        $calendarOverrides = $this->tab === 'calendar'
            ? \App\Models\EmployeePolicyOverride::with('employee:id,name,employee_code')->where('rule_key', 'calendar.working_days')->get()
                ->filter(fn ($o) => $o->employee)->sortBy(fn ($o) => $o->employee->name)->values()
            : collect();

        return $this->page('livewire.system.settings', [
            'leaveTypes' => LeaveType::orderBy('name')->get(), 'offices' => OfficeLocation::orderBy('name')->get(), 'canCapture' => $canCapture, 'captureEmployees' => $captureEmployees,
            'allEmployees' => $this->tab === 'calendar' ? \App\Models\AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name', 'employee_code']) : collect(),
            'calendarOverrides' => $calendarOverrides,
            'sheetsStatus' => $this->tab === 'sheets' ? ['stored_secret' => (bool) app(\App\Services\GoogleSheetsSync::class)->settings()['secret'], 'last' => app(\App\Services\GoogleSheetsSync::class)->lastResult()] : null,
            'mail' => ['mailer' => config('mail.default'), 'host' => config('mail.mailers.'.config('mail.default').'.host'), 'port' => config('mail.mailers.'.config('mail.default').'.port'), 'from' => config('mail.from.address'), 'queue' => config('queue.default'), 'last_error' => \Illuminate\Support\Facades\Cache::get(\App\Notifications\Channels\SafeMailChannel::LAST_ERROR_KEY)],
        ], 'Settings');
    }
}
