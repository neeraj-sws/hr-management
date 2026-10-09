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
    public string $idleMinutes = '10';

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
    public string $otMaxPerDay = '';
    public string $otHolidayMultiplier = '';
    public string $otMaxPerMonthHours = '';
    public string $workingDaysPerMonth = '22';
    public bool $attendanceProration = true;
    public bool $includePaidLeave = true;
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

        $this->fill([
            'workdays' => array_map('intval', $calendar['days'] ?? [1, 2, 3, 4, 5]),
            'holidays' => implode("\n", $calendar['holidays'] ?? []),
            'holidayNames' => (array) ($calendar['holiday_names'] ?? []),
            'idleMinutes' => (string) ($idle['away_prompt_minutes'] ?? 10),
            'requireLocation' => (bool) ($location['required_for_mobile'] ?? false),
            'captureEnabled' => (bool) ($capture['enabled'] ?? false), 'captureInterval' => (string) ($capture['interval_minutes'] ?? 10), 'captureActiveOnly' => (bool) ($capture['active_only'] ?? true),
            'captureWindowStart' => (string) ($capture['window_start'] ?? ''), 'captureWindowEnd' => (string) ($capture['window_end'] ?? ''), 'captureRetention' => (string) ($capture['retention_days'] ?? 30),
            'startTime' => (string) ($hours['start_time'] ?? ''), 'endTime' => (string) ($hours['end_time'] ?? ''),
            'dailyHours' => (string) ($hours['standard_daily_hours'] ?? 8),
            'graceMinutes' => (string) ($rules['grace_minutes'] ?? 0), 'halfDayHours' => (string) ($rules['half_day_hours'] ?? 4),
            'otMultiplier' => (string) ($ot['multiplier'] ?? ''), 'otMaxPerDay' => (string) ($ot['max_minutes_per_day'] ?? ''), 'otHolidayMultiplier' => (string) ($ot['holiday_multiplier'] ?? ''), 'otMaxPerMonthHours' => isset($ot['max_minutes_per_month']) ? (string) round($ot['max_minutes_per_month'] / 60, 2) : '',
            'workingDaysPerMonth' => (string) ($payroll['working_days_per_month'] ?? 22),
            'attendanceProration' => (bool) ($payroll['attendance_proration'] ?? true), 'includePaidLeave' => (bool) ($payroll['include_paid_leave'] ?? true),
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
        if (trim($this->newHolidayName) !== '') $this->holidayNames[$this->newHoliday] = trim($this->newHolidayName);
        $this->newHoliday = '';
        $this->newHolidayName = '';
    }

    public function removeHoliday(string $date): void
    {
        $this->authorizeSettings();
        $this->holidays = implode("\n", collect($this->holidayList())->reject(fn ($d) => $d === $date)->values()->all());
        unset($this->holidayNames[$date]);
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
        $people->setPolicy('calendar.working_days', 'calendar', 'Working calendar', ['days' => array_map('intval', $this->workdays), 'holidays' => $holidays->sort()->values()->all(), 'holiday_names' => array_intersect_key(array_map(fn ($name) => mb_substr(trim((string) $name), 0, 80), $this->holidayNames), array_flip($holidays->all()))], null, auth()->id(), today()->toDateString());
        $this->toast('Working calendar saved.');
    }

    public function saveHours(PeopleOperationsService $people): void
    {
        $this->authorizeSettings();
        $this->validate(['startTime' => ['nullable', 'date_format:H:i'], 'endTime' => ['nullable', 'date_format:H:i'], 'dailyHours' => ['required', 'numeric', 'gt:0', 'max:24'], 'graceMinutes' => ['required', 'integer', 'min:0', 'max:240'], 'halfDayHours' => ['required', 'numeric', 'gt:0', 'max:24'], 'idleMinutes' => ['required', 'integer', 'min:0', 'max:240']], [], ['idleMinutes' => 'away minutes']);
        $people->setPolicy('work.hours', 'attendance', 'Work hours', array_filter(['start_time' => $this->startTime ?: null, 'end_time' => $this->endTime ?: null, 'standard_daily_hours' => (float) $this->dailyHours], fn ($v) => $v !== null), null, auth()->id(), today()->toDateString());
        $people->setPolicy('attendance.rules', 'attendance', 'Attendance rules', ['grace_minutes' => (int) $this->graceMinutes, 'half_day_hours' => (float) $this->halfDayHours], null, auth()->id(), today()->toDateString());
        $people->setPolicy('attendance.idle', 'attendance', 'Away-time prompt (desktop)', ['away_prompt_minutes' => (int) $this->idleMinutes], null, auth()->id(), today()->toDateString());
        $this->toast('Work hours and attendance rules saved.');
    }

    public function savePayroll(PeopleOperationsService $people): void
    {
        $this->authorizeSettings();
        $this->validate(['otMultiplier' => ['nullable', 'numeric', 'min:0', 'max:10'], 'otMaxPerDay' => ['nullable', 'integer', 'min:1', 'max:1440'], 'otHolidayMultiplier' => ['nullable', 'numeric', 'min:0', 'max:10'], 'otMaxPerMonthHours' => ['nullable', 'numeric', 'min:0.25', 'max:400'], 'workingDaysPerMonth' => ['required', 'integer', 'min:1', 'max:31'], 'advanceLeaveMax' => ['required', 'numeric', 'min:0', 'max:60'], 'encashmentRate' => ['required', 'numeric', 'min:0']]);
        $ot = array_filter(['multiplier' => $this->otMultiplier === '' ? null : (float) $this->otMultiplier, 'max_minutes_per_day' => $this->otMaxPerDay === '' ? null : (int) $this->otMaxPerDay, 'holiday_multiplier' => $this->otHolidayMultiplier === '' ? null : (float) $this->otHolidayMultiplier, 'max_minutes_per_month' => $this->otMaxPerMonthHours === '' ? null : (int) round((float) $this->otMaxPerMonthHours * 60)], fn ($v) => $v !== null);
        $people->setPolicy('overtime.multiplier', 'overtime', 'Overtime', $ot, null, auth()->id(), today()->toDateString());
        $people->setPolicy('payroll.calculation', 'payroll', 'Payroll calculation', ['working_days_per_month' => (int) $this->workingDaysPerMonth, 'attendance_proration' => $this->attendanceProration, 'include_paid_leave' => $this->includePaidLeave], null, auth()->id(), today()->toDateString());
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

    public function saveCapturePolicy(PeopleOperationsService $people): void
    {
        $this->authorizeSettings();
        $this->validate(['captureInterval' => ['required', Rule::in(array_map('strval', \App\Services\ScreenCaptureService::INTERVALS))], 'captureWindowStart' => ['nullable', 'date_format:H:i', 'required_with:captureWindowEnd'], 'captureWindowEnd' => ['nullable', 'date_format:H:i', 'required_with:captureWindowStart'], 'captureRetention' => ['required', 'integer', 'min:1', 'max:365']], [], ['captureInterval' => 'interval', 'captureWindowStart' => 'window start', 'captureWindowEnd' => 'window end', 'captureRetention' => 'retention']);
        $people->setPolicy('monitoring.screenshots', 'monitoring', 'Screen capture', array_filter(['enabled' => $this->captureEnabled, 'interval_minutes' => (int) $this->captureInterval, 'active_only' => $this->captureActiveOnly, 'window_start' => $this->captureWindowStart ?: null, 'window_end' => $this->captureWindowEnd ?: null, 'retention_days' => (int) $this->captureRetention], fn ($v) => $v !== null), null, auth()->id(), today()->toDateString());
        $this->toast($this->captureEnabled ? 'Screen capture is on. Employees are asked to acknowledge it in the desktop app first.' : 'Screen capture is off.');
    }

    public function saveLocationPolicy(PeopleOperationsService $people): void
    {
        $this->authorizeSettings();
        if ($this->requireLocation && ! OfficeLocation::where('is_active', true)->exists()) {
            $this->requireLocation = false;
            $this->addError('requireLocation', 'Add at least one active office before requiring location.');

            return;
        }
        $people->setPolicy('attendance.location', 'attendance', 'Mobile check-in location', ['required_for_mobile' => $this->requireLocation], null, auth()->id(), today()->toDateString());
        $this->toast($this->requireLocation ? 'Mobile check-in now needs to be at an office.' : 'Mobile check-in no longer needs a location.');
    }

    public function render()
    {
        return $this->page('livewire.system.settings', ['leaveTypes' => LeaveType::orderBy('name')->get(), 'offices' => OfficeLocation::orderBy('name')->get()], 'Settings');
    }
}
