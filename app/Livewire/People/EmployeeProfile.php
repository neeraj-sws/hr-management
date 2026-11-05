<?php

namespace App\Livewire\People;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\LeaveType;
use App\Services\AttendanceService;
use App\Services\LeaveAccrualService;
use App\Services\WorkService;
use Livewire\Component;

class EmployeeProfile extends Component
{
    use InteractsWithHr, \Livewire\WithFileUploads;

    public AttendanceEmployee $employee;

    /** Compliance tab: a month (Y-m; empty = last 90 days) and a kind (late / early / no_status / late_status; empty = all). */
    public string $complianceMonth = '';
    /** Attendance tab: the month shown in the calendar (Y-m; empty = this month). */
    public string $attendanceMonth = '';
    public string $complianceType = '';

    /** Leave tab: the month shown (Y-m; empty = this month). */
    public string $leaveMonth = '';
    /** "Record as leave" for a day absent with no leave (HR). */
    public bool $showRecordLeave = false;
    public string $recordDate = '';
    public ?int $recordTypeId = null;
    public string $recordDuration = 'full';
    public string $recordNote = '';

    /** Tab shown first (?tab=docs from a link). */
    public string $initialTab = 'overview';

    /** "Generate letter" (HR / admin): template, the filled-in letter HR can edit, and profile fields that were empty. */
    public bool $letterOpen = false;
    public ?int $letterTemplateId = null;
    public string $letterBody = '';
    public array $letterMissing = [];

    /** "Daily status for earlier days" (HR): how far back - N months, or from a date - and optionally until a date. */
    public string $pastStatusBy = 'months';
    public int $pastStatusMonths = 1;
    public string $pastStatusFrom = '';
    public string $pastStatusUntil = '';

    public function openRecordLeave(string $date): void
    {
        abort_unless($this->user()->canAny(['leave.manage', 'hr.manage']), 403);
        $this->fill(['recordDate' => $date, 'recordTypeId' => LeaveType::where('is_active', true)->orderByDesc('paid')->orderBy('name')->value('id'), 'recordDuration' => 'full', 'recordNote' => '', 'showRecordLeave' => true]);
        $this->resetValidation();
    }

    /** Records an approved leave for a day the person was absent (e.g. they told HR but did not apply). */
    public function recordLeave(\App\Services\HrWorkflowService $workflow): void
    {
        abort_unless($this->user()->canAny(['leave.manage', 'hr.manage']), 403);
        \App\Services\ImpersonationService::block();
        $this->validate(['recordDate' => ['required', 'date', 'before_or_equal:today'], 'recordTypeId' => ['required', \Illuminate\Validation\Rule::exists('leave_types', 'id')->where('is_active', true)], 'recordDuration' => ['required', 'in:full,half,short'], 'recordNote' => ['required', 'string', 'min:3', 'max:500']],
            ['recordNote.required' => 'Write why, e.g. "Informed HR on phone: fever".'], ['recordDate' => 'date', 'recordTypeId' => 'leave type', 'recordDuration' => 'duration', 'recordNote' => 'reason']);
        $day = \Illuminate\Support\Carbon::parse($this->recordDate)->startOfDay();
        $leave = $workflow->requestLeave($this->employee, ['leave_type_id' => $this->recordTypeId, 'starts_on' => $day, 'ends_on' => $day->copy(), 'duration' => $this->recordDuration, 'reason' => trim($this->recordNote)], false);
        $workflow->decideLeave($leave, $this->user()->id, true, 'Recorded by HR: '.trim($this->recordNote));
        $this->showRecordLeave = false;
        $this->toast('Leave recorded for '.$day->format('d M Y').'.');
    }

    /** New photo being uploaded (saved as soon as it is chosen). */
    public $photo = null;

    public function updatedPhoto(\App\Services\EmployeePhotoService $photos): void
    {
        $this->validate(['photo' => \App\Services\EmployeePhotoService::RULES], ['photo.mimes' => 'Choose a JPG, PNG or WebP picture.', 'photo.max' => 'The picture must be 5 MB or smaller.']);
        $photos->save($this->employee, $this->photo, $this->user());
        $this->reloadWith('Photo updated.');
    }

    public function removePhoto(\App\Services\EmployeePhotoService $photos): void
    {
        $photos->remove($this->employee, $this->user());
        $this->reloadWith('Photo removed.');
    }

    /** Reloads the page so the new photo shows everywhere at once (header, top bar, lists). */
    private function reloadWith(string $message): void
    {
        session()->flash('toast', ['message' => $message, 'type' => 'success']);
        $this->redirect(route('people.employees.show', $this->employee));
    }

    public function mount(AttendanceEmployee $employee): void
    {
        abort_unless($this->user()->can('view', $employee), 403);
        $this->employee = $employee;
        $this->leaveMonth = now()->format('Y-m');
        $tab = (string) request()->query('tab', '');
        if (in_array($tab, ['overview', 'attendance', 'leave', 'work', 'payroll', 'docs', 'compliance', 'settings', 'history'], true)) $this->initialTab = $tab;
        // ?letter=appointment (after adding an employee) / relieving / experience_issued (after an exit): open that letter.
        $letter = (string) request()->query('letter', '');
        if ($letter !== '' && \App\Services\HrLetterService::canManage($this->user())) {
            $this->initialTab = 'docs';
            $this->openLetter($letter);
        }
    }

    public function openLetter(?string $category = null): void
    {
        abort_unless(\App\Services\HrLetterService::canManage($this->user()), 403);
        $letters = app(\App\Services\HrLetterService::class);
        $template = ($category ? $letters->templateFor($category) : null) ?? \App\Models\LetterTemplate::where('is_active', true)->orderBy('name')->first();
        $this->resetErrorBag();
        $this->letterOpen = true;
        $this->letterTemplateId = $template?->id;
        $this->fillLetter();
    }

    /** Another template chosen: its wording replaces the letter (run after the request's property updates). */
    public function chooseTemplate($templateId = null): void
    {
        abort_unless(\App\Services\HrLetterService::canManage($this->user()), 403);
        $this->resetErrorBag();
        $this->letterTemplateId = $templateId !== null && $templateId !== '' ? (int) $templateId : null;
        $this->fillLetter();
    }

    /** The chosen template with this employee's data filled in (HR can still change any word before issuing). */
    private function fillLetter(): void
    {
        $template = $this->letterTemplateId ? \App\Models\LetterTemplate::where('is_active', true)->find($this->letterTemplateId) : null;
        $filled = $template ? app(\App\Services\HrLetterService::class)->fill($template->body, $this->employee) : ['html' => '', 'missing' => []];
        $this->letterBody = $filled['html'];
        $this->letterMissing = $filled['missing'];
        // The editor keeps its own text; send it the new letter.
        $this->dispatch('letter-editor-set', model: 'letterBody', html: $this->letterBody);
    }

    public function closeLetter(): void
    {
        $this->letterOpen = false;
    }

    /** The letter as it is now, as a PDF to check (not saved, not sent to the employee). */
    public function letterPdf()
    {
        abort_unless(\App\Services\HrLetterService::canManage($this->user()), 403);
        $template = \App\Models\LetterTemplate::findOrFail((int) $this->letterTemplateId);
        $pdf = app(\App\Services\HrLetterService::class)->pdf($this->letterBody, $template->name.' - '.$this->employee->name);

        return response()->streamDownload(fn () => print($pdf), 'Draft - '.preg_replace('/[^A-Za-z0-9 ._-]+/', '-', $template->name.' - '.$this->employee->name).'.pdf', ['Content-Type' => 'application/pdf']);
    }

    /** Saves the letter as a PDF in the employee's documents (they are told and can download it). */
    public function issueLetter(): void
    {
        $this->resetErrorBag();
        $template = \App\Models\LetterTemplate::where('is_active', true)->find((int) $this->letterTemplateId);
        if (! $template) throw \Illuminate\Validation\ValidationException::withMessages(['letterTemplateId' => 'Choose a template.']);
        app(\App\Services\HrLetterService::class)->issue($this->employee, $template, $this->letterBody, $this->user());
        $this->letterOpen = false;
        $this->toast($template->name.' issued: saved in Docs & assets, and the employee was told.');
    }

    /** "Training certificate" (HR / admin): what it says, filled in and changeable, then saved to the documents. */
    public bool $certOpen = false;
    public array $cert = [];

    public function openCertificate(): void
    {
        abort_unless(\App\Services\HrLetterService::canManage($this->user()), 403);
        $this->resetErrorBag();
        $this->cert = app(\App\Services\HrLetterService::class)->certificateDefaults($this->employee);
        $this->certOpen = true;
    }

    private function validateCertificate(): void
    {
        abort_unless(\App\Services\HrLetterService::canManage($this->user()), 403);
        $this->validate([
            'cert.salutation' => ['nullable', 'in:Mr.,Ms.,Mrs.'], 'cert.name' => ['required', 'string', 'max:120'], 'cert.relation' => ['required', 'in:Son of,Daughter of,Wife of'],
            'cert.parent' => ['nullable', 'string', 'max:120'], 'cert.course' => ['required', 'string', 'max:120'], 'cert.from' => ['required', 'string', 'max:40'], 'cert.to' => ['required', 'string', 'max:40'],
            'cert.date' => ['required', 'string', 'max:40'], 'cert.tagline' => ['nullable', 'string', 'max:80'],
        ], [], ['cert.name' => 'name', 'cert.course' => 'course', 'cert.from' => 'from', 'cert.to' => 'to', 'cert.date' => 'date', 'cert.parent' => "father's / mother's name"]);
    }

    /** The certificate as it is now, to check (not saved). */
    public function certificatePdf()
    {
        $this->validateCertificate();
        $pdf = app(\App\Services\HrLetterService::class)->certificatePdf($this->cert);

        return response()->streamDownload(fn () => print($pdf), 'Draft - Training certificate - '.preg_replace('/[^A-Za-z0-9 ._-]+/', '-', $this->employee->name).'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function closeCertificate(): void
    {
        $this->certOpen = false;
    }

    public function issueCertificate(): void
    {
        $this->validateCertificate();
        app(\App\Services\HrLetterService::class)->issueCertificate($this->employee, $this->cert, $this->user());
        $this->certOpen = false;
        $this->toast('Training certificate issued: saved in Docs & assets, and the employee was told.');
    }

    public function confirmEmployment(\App\Services\EmployeeService $employees): void
    {
        abort_unless($this->user()->canAny(['employees.manage', 'hr.manage']), 403);
        $employees->confirm($this->employee, today()->toDateString(), $this->user());
        $this->employee->refresh();
        $this->toast('Employment confirmed.');
    }

    /** Admin sets which ways this employee may mark attendance (web / mobile / desktop). */
    public function setAttendanceSources(array $sources, \App\Services\PeopleOperationsService $people, \App\Services\AttendanceService $attendance): void
    {
        abort_unless($this->user()->canAny(['employees.manage', 'hr.manage']), 403);
        $sources = array_values(array_intersect(array_keys(\App\Services\AttendanceService::SOURCES), $sources));
        if (! $sources) { $this->toast('Keep at least one way to mark attendance.'); return; }
        $people->setPolicy('attendance.sources', 'attendance', 'Attendance methods', ['allowed' => $sources], $this->employee->id, $this->user()->id, today()->toDateString());
        $this->toast('Attendance methods updated.');
    }

    /** Daily status for this person: compulsory + reminder, compulsory only, not compulsory, or the company default. */
    public function setDailyStatusRule(string $mode): void
    {
        abort_unless($this->user()->canAny(['employees.manage', 'hr.manage']), 403);
        \App\Services\ImpersonationService::block();
        if ($mode === 'default') \App\Support\DailyStatusRule::useDefault($this->employee);
        else \App\Support\DailyStatusRule::set($this->employee, $mode, $this->user()->id);
        app(\App\Services\AuditLogger::class)->record('daily_status.rule_set', $this->employee, ['mode' => $mode]);
        $this->toast('Daily status: '.\App\Support\DailyStatusRule::MODES[\App\Support\DailyStatusRule::mode($this->employee)].' (from today).');
    }

    /** HR lets this person write a daily status for days in previous months (not only the last 2 days). */
    public function savePastStatus(): void
    {
        abort_unless($this->user()->canAny(['employees.manage', 'hr.manage']), 403);
        \App\Services\ImpersonationService::block();
        $this->validate([
            'pastStatusBy' => ['required', 'in:months,date'],
            'pastStatusMonths' => ['required_if:pastStatusBy,months', 'integer', 'min:1', 'max:'.\App\Support\PastDailyStatus::MAX_MONTHS],
            'pastStatusFrom' => ['required_if:pastStatusBy,date', 'nullable', 'date', 'before:today'],
            'pastStatusUntil' => ['nullable', 'date', 'after_or_equal:today'],
        ], ['pastStatusFrom.required_if' => 'Choose the earliest day.'], ['pastStatusMonths' => 'months', 'pastStatusFrom' => 'earliest day', 'pastStatusUntil' => 'allowed until']);
        $byDate = $this->pastStatusBy === 'date';
        \App\Support\PastDailyStatus::set($this->employee, $byDate ? null : $this->pastStatusMonths, $byDate ? $this->pastStatusFrom : null, $this->pastStatusUntil ?: null, $this->user()->id);
        $earliest = \App\Support\PastDailyStatus::earliest($this->employee);
        app(\App\Services\AuditLogger::class)->record('daily_status.past_days_set', $this->employee, ['months' => $byDate ? null : $this->pastStatusMonths, 'from' => $byDate ? $this->pastStatusFrom : null, 'until' => $this->pastStatusUntil ?: null, 'earliest' => $earliest]);
        $this->toast($this->employee->name.' can now write a daily status for days from '.($earliest ? \Illuminate\Support\Carbon::parse($earliest)->format('d M Y') : 'the joining date').($this->pastStatusUntil ? ' (until '.\Illuminate\Support\Carbon::parse($this->pastStatusUntil)->format('d M Y').')' : '').'.');
    }

    public function stopPastStatus(): void
    {
        abort_unless($this->user()->canAny(['employees.manage', 'hr.manage']), 403);
        \App\Services\ImpersonationService::block();
        \App\Support\PastDailyStatus::stop($this->employee);
        app(\App\Services\AuditLogger::class)->record('daily_status.past_days_off', $this->employee);
        $this->toast('Daily status for earlier days turned off: the normal 2-day limit applies again.');
    }

    public function toggleAppUsage(\App\Services\AppUsageService $usage): void
    {
        $on = ! $usage->enabledFor($this->employee);
        $usage->setForEmployee($this->employee, $on, $this->user());
        $this->toast($on ? 'Application usage tracking turned on.' : 'Application usage tracking turned off.');
    }

    public function toggleWindowTitles(\App\Services\AppUsageService $usage): void
    {
        $on = ! $usage->titlesEnabledFor($this->employee);
        $usage->setTitlesForEmployee($this->employee, $on, $this->user());
        $this->toast($on ? 'Window-title tracking turned on.' : 'Window-title tracking turned off.');
    }

    public function toggleCapture(\App\Services\ScreenCaptureService $captures): void
    {
        $on = ! $captures->policy($this->employee)['enabled'];
        $captures->setForEmployee($this->employee, $on, $this->user());
        $this->toast($on ? 'Screen capture turned on.' : 'Screen capture turned off.');
    }

    public function render(AttendanceService $attendance, LeaveAccrualService $leave, WorkService $work)
    {
        $employee = $this->employee->load(['department', 'manager', 'teams', 'user.roles', 'directReports', 'salaryProfiles' => fn ($q) => $q->orderByDesc('effective_from')]);
        $user = $this->user();
        $days = $attendance->days($employee, now()->startOfMonth(), today());
        $summary = $days->countBy('status');
        $balances = LeaveType::where('is_active', true)->get()->map(fn ($type) => ['type' => $type, ...$leave->balance($employee, $type, \App\Support\LeaveYear::current())]);
        $metrics = $work->productivity(collect([$employee]), now()->startOfMonth()->toDateString(), today()->toDateString())->first();
        $isSelf = $employee->user_id === $user->id;
        $showSalary = $isSelf || $user->canAny(['payroll.manage', 'payroll.approve', 'hr.manage']);
        $projectQuery = $work->projectsInvolving([$employee->id]);
        $projects = (clone $projectQuery)->with('manager:id,name')
            ->withCount(['tasks as my_tasks_count' => fn ($q) => $q->where('assignee_id', $employee->id), 'tasks as my_open_tasks_count' => fn ($q) => $q->where('assignee_id', $employee->id)->where('status', '!=', 'completed')])
            ->orderByRaw("case status when 'active' then 0 when 'planned' then 1 when 'on_hold' then 2 else 3 end")->orderBy('name')->limit(20)->get();

        // Attendance discipline over the last 90 days: late arrivals, early check-outs, and worked days
        // with no daily status (or one submitted after the day).
        $month = preg_match('/^\d{4}-\d{2}$/', $this->complianceMonth) ? \Illuminate\Support\Carbon::parse($this->complianceMonth.'-01') : null;
        $from = $month ? $month->copy()->startOfMonth() : today()->subDays(89);
        $to = $month ? $month->copy()->endOfMonth()->min(today()) : today();
        if ($employee->joined_at && $from->lt($employee->joined_at)) $from = $employee->joined_at->copy();
        $statuses = \App\Models\DailyWorkStatus::where('employee_id', $employee->id)->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)->get()->keyBy(fn ($s) => $s->work_date->toDateString());
        $discipline = collect();
        foreach ($from->lte($to) ? $attendance->days($employee, $from, $to) : [] as $day) {
            $session = $day['session'];
            if (! $session) continue;
            if ($attendance->isLate($session)) $discipline->push(['date' => $day['date'], 'type' => 'late', 'label' => 'Late arrival', 'detail' => 'In '.$session->check_in->format('h:i A').' · late by '.\App\Support\Duration::fromMinutes($attendance->lateMinutes($session))]);
            if ($session->check_out && ($early = $attendance->earlyMinutes($session)) > 0) $discipline->push(['date' => $day['date'], 'type' => 'early', 'label' => 'Early check-out', 'detail' => 'Out '.$session->check_out->format('h:i A').' · early by '.\App\Support\Duration::fromMinutes($early)]);
            if ($day['date'] < today()->toDateString()) {
                $status = $statuses->get($day['date']);
                if (! $status && \App\Support\DailyStatusRule::required($employee, $day['date'])) $discipline->push(['date' => $day['date'], 'type' => 'no_status', 'label' => 'Daily status missing', 'detail' => 'Worked but no daily status was submitted']);
                elseif ($status && $status->submitted_at && $status->submitted_at->toDateString() > $day['date']) $discipline->push(['date' => $day['date'], 'type' => 'late_status', 'label' => 'Daily status late', 'detail' => 'Submitted '.$status->submitted_at->format('d M, h:i A')]);
            }
        }
        $canSeeDocs = $isSelf || $user->canAny(['lifecycle.manage', 'hr.manage', 'employees.manage']) || \App\Support\Access::isAdmin($user);

        return $this->page('livewire.people.employee-profile', [
            'employee' => $employee,
            'calendarMonth' => $calMonth = (preg_match('/^\d{4}-\d{2}$/', $this->attendanceMonth) ? \Illuminate\Support\Carbon::parse($this->attendanceMonth.'-01') : today()->startOfMonth()),
            'calendarDays' => $attendance->days($employee, $calMonth->copy()->startOfMonth(), $calMonth->copy()->endOfMonth()),
            'discipline' => $discipline->when($this->complianceType !== '', fn ($c) => $c->where('type', $this->complianceType))->sortByDesc('date')->values(),
            'disciplineCounts' => $discipline->countBy('type'),
            'disciplinePeriod' => $month ? $month->format('F Y') : 'Last 90 days',
            'complianceMonths' => collect(range(0, 11))->map(fn ($i) => today()->startOfMonth()->subMonths($i))->filter(fn ($m) => ! $employee->joined_at || $m->gte($employee->joined_at->copy()->startOfMonth()))->mapWithKeys(fn ($m) => [$m->format('Y-m') => $m->format('F Y')]),
            'dailyStatuses' => \App\Models\DailyWorkStatus::where('employee_id', $employee->id)->latest('work_date')->limit(10)->get(),
            'canChangePhoto' => \App\Services\EmployeePhotoService::canChange($user, $employee),
            'isManager' =>$employee->directReports->where('is_active', true)->isNotEmpty(),
            'summary' => $summary,
            // The working day attendance and payroll really use (per-person rule, else the employee's hours).
            'dailyHours' => (float) $attendance->dailyHours($employee),
            'recentDays' => $days->reverse()->take(10),
            'balances' => $balances,
            'metrics' => $metrics,
            'projectCounts' => $work->projectCounts($projectQuery),
            'projects' => $projects,
            'tasks' => $employee->tasks()->where('status', '!=', 'completed')->latest()->limit(8)->get(),
            'assets' => $employee->assets()->latest()->get(),
            'documents' => $canSeeDocs ? $employee->documents()->latest()->get() : collect(),
            'canIssueLetters' => \App\Services\HrLetterService::canManage($user),
            'letterTemplates' => $this->letterOpen ? \App\Models\LetterTemplate::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
            // Home address and emergency contact: the person, HR and admins (the same people who see documents).
            'canSeeContact' => $canSeeDocs,
            'showSalary' => $showSalary,
            'salarySoFar' => $showSalary ? app(\App\Services\SalarySummaryService::class)->soFar($employee) : null,
            'salaryHistory' => $showSalary ? app(\App\Services\SalarySummaryService::class)->history($employee) : [],
            'canEdit' => $user->canAny(['employees.manage', 'hr.manage']),
            'dailyStatusMode' => \App\Support\DailyStatusRule::mode($employee),
            'dailyStatusOwn' => \App\Support\DailyStatusRule::hasOwnChoice($employee),
            'pastStatus' => \App\Support\PastDailyStatus::rule($employee),
            'pastStatusEarliest' => \App\Support\PastDailyStatus::earliest($employee),
            'capture' => $user->can('monitoring.manage') ? app(\App\Services\ScreenCaptureService::class)->policy($employee) : null,
            'attendanceSources' => app(\App\Services\AttendanceService::class)->allowedSources($employee),
            'appUsageOn' => $user->can('monitoring.manage') ? app(\App\Services\AppUsageService::class)->enabledFor($employee) : null,
            'windowTitlesOn' => $user->can('monitoring.manage') ? app(\App\Services\AppUsageService::class)->titlesEnabledFor($employee) : null,
            'teamHistory' => \App\Models\EmployeeTeamHistory::with('team')->where('employee_id', $employee->id)->orderByDesc('starts_on')->get(),
            'managerHistory' => \App\Models\EmployeeManagerHistory::with('manager')->where('employee_id', $employee->id)->orderByDesc('starts_on')->get(),
            'history' => $user->canAny(['employees.manage', 'hr.manage', 'audit.view']) ? \App\Models\AuditLog::with('actor')->where('subject_type', $employee::class)->where('subject_id', $employee->id)->latest('id')->limit(30)->get() : collect(),
            // History tab: the employment story (what matters to HR); the full change log is in Security > Audit log.
            'journey' => \App\Support\EmploymentHistory::for($employee, $user, $showSalary),
            'overtime' => $employee->overtimeRequests()->latest('work_date')->limit(10)->get(),
            'overtimeOn' => app(\App\Services\PeopleOperationsService::class)->overtimeEnabled($employee),
            'expenses' => $employee->expenseClaims()->latest('spent_on')->limit(10)->get(),
            'penalties' => \App\Models\EmployeePenalty::with('policyRule')->where('employee_id', $employee->id)->latest('effective_on')->limit(10)->get(),
            'payslips' => $showSalary ? $employee->payslips()->with('item.run')->latest('issued_at')->limit(10)->get() : collect(),
            'reviews' => $employee->reviews()->with('reviewer')->latest('period_ends_on')->limit(6)->get(),
            'training' => $employee->trainingAssignments()->with('course')->latest()->limit(10)->get(),
            'recentLeave' => $employee->leaveRequests()->with('leaveType')->latest('starts_on')->limit(10)->get(),
            // Leave tab: totals, every request and the days absent with no leave for the chosen leave year.
            'leaveHistory' => \App\Support\LeaveHistory::month($employee, preg_match('/^\d{4}-\d{2}$/', $this->leaveMonth) ? $this->leaveMonth : now()->format('Y-m')),
            'recordTypes' => $this->showRecordLeave ? LeaveType::where('is_active', true)->orderByDesc('paid')->orderBy('name')->get() : collect(),
            'canRecordLeave' => $user->canAny(['leave.manage', 'hr.manage']),

        ], $employee->name);
    }
}
