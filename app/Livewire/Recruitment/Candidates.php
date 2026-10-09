<?php

namespace App\Livewire\Recruitment;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\Interview;
use App\Models\JobOpening;
use App\Models\User;
use App\Services\RecruitmentService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

class Candidates extends Component
{
    use InteractsWithHr, WithFileUploads;

    #[Url] public string $job = '';
    #[Url(as: 'q')] public string $search = '';

    public bool $showAdd = false;
    public string $jobId = '';
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $source = 'job_portal';
    public string $notes = '';
    public $resume = null;

    public ?int $viewingId = null;
    public string $stage = '';
    public string $scheduledAt = '';
    public string $duration = '45';
    public string $mode = 'video';
    public string $interviewLocation = '';
    public string $interviewerId = '';

    public bool $showHire = false;
    public string $employeeCode = '';
    public string $password = '';
    public string $joinedAt = '';
    public string $designation = '';
    public string $departmentId = '';
    public string $managerId = '';
    public string $monthlySalary = '';

    public function openAdd(): void
    {
        $this->reset('name', 'email', 'phone', 'notes', 'resume');
        $this->source = 'job_portal';
        $this->jobId = $this->job ?: (string) (JobOpening::where('status', 'open')->value('id') ?? '');
        $this->resetValidation();
        $this->showAdd = true;
    }

    public function add(RecruitmentService $recruitment): void
    {
        $this->validate([
            'jobId' => ['required', 'exists:job_openings,id'],
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190', Rule::unique('candidates', 'email')->where('job_opening_id', $this->jobId)],
            'phone' => ['nullable', 'string', 'max:40'],
            'source' => ['required', Rule::in(array_keys(Candidate::SOURCES))],
            'notes' => ['nullable', 'string', 'max:5000'],
            'resume' => RecruitmentService::RESUME_RULES,
        ], ['email.unique' => 'This candidate has already applied for this opening.'], ['jobId' => 'job opening', 'resume' => 'CV']);
        $candidate = $recruitment->addCandidate(['job_opening_id' => $this->jobId, 'name' => $this->name, 'email' => $this->email, 'phone' => $this->phone ?: null, 'source' => $this->source, 'notes' => $this->notes ?: null], $this->resume, auth()->id());
        $this->showAdd = false;
        $this->toast("{$candidate->name} added to the pipeline.");
    }

    public function view(int $id): void
    {
        $candidate = Candidate::findOrFail($id);
        $this->fill(['viewingId' => $id, 'stage' => $candidate->stage, 'scheduledAt' => now()->addDay()->setTime(11, 0)->format('Y-m-d\TH:i'), 'duration' => '45', 'mode' => 'video', 'interviewLocation' => '', 'interviewerId' => (string) ($candidate->jobOpening?->hiring_manager_id ?? '')]);
        $this->resetValidation();
    }

    public function moveTo(int $id, string $stage, RecruitmentService $recruitment): void
    {
        $recruitment->moveStage(Candidate::findOrFail($id), $stage);
        $this->toast('Moved to '.(Candidate::STAGES[$stage] ?? $stage).'.');
    }

    public function updatedStage(string $value): void
    {
        if ($this->viewingId) $this->moveTo($this->viewingId, $value, app(RecruitmentService::class));
    }

    public function schedule(RecruitmentService $recruitment): void
    {
        $this->validate([
            'scheduledAt' => ['required', 'date'],
            'duration' => ['required', 'integer', 'min:10', 'max:480'],
            'mode' => ['required', Rule::in(array_keys(Interview::MODES))],
            'interviewLocation' => ['nullable', 'string', 'max:255'],
            'interviewerId' => ['required', 'exists:users,id'],
        ], [], ['scheduledAt' => 'date and time', 'interviewerId' => 'interviewer', 'interviewLocation' => 'place or link']);
        $recruitment->scheduleInterview(Candidate::findOrFail($this->viewingId), ['scheduled_at' => $this->scheduledAt, 'duration_minutes' => (int) $this->duration, 'mode' => $this->mode, 'location' => $this->interviewLocation ?: null, 'interviewer_id' => (int) $this->interviewerId]);
        $this->stage = Candidate::find($this->viewingId)?->stage ?? $this->stage;
        $this->toast('Interview scheduled — the interviewer has been notified.');
    }

    public function cancelInterview(int $id, RecruitmentService $recruitment): void
    {
        $recruitment->cancelInterview(Interview::findOrFail($id));
        $this->toast('Interview cancelled.');
    }

    public function openHire(): void
    {
        $candidate = Candidate::with('jobOpening')->findOrFail($this->viewingId);
        $this->fill([
            'employeeCode' => '', 'password' => '', 'joinedAt' => today()->addDays(7)->toDateString(),
            'designation' => (string) $candidate->jobOpening?->title, 'departmentId' => (string) ($candidate->jobOpening?->department_id ?? ''),
            'managerId' => (string) (AttendanceEmployee::where('user_id', $candidate->jobOpening?->hiring_manager_id)->value('id') ?? ''), 'monthlySalary' => '',
        ]);
        $this->resetValidation();
        $this->showHire = true;
    }

    public function hire(RecruitmentService $recruitment): void
    {
        $candidate = Candidate::findOrFail($this->viewingId);
        $this->validate([
            'employeeCode' => ['required', 'string', 'max:100', 'alpha_dash', Rule::unique('attendance_employees', 'employee_code')],
            'password' => ['required', 'string', Password::min(12)->letters()->numbers()],
            'joinedAt' => ['required', 'date'],
            'designation' => ['nullable', 'string', 'max:120'],
            'departmentId' => ['nullable', 'exists:departments,id'],
            'managerId' => ['nullable', 'exists:attendance_employees,id'],
            'monthlySalary' => ['required', 'numeric', 'min:0'],
        ], [], ['employeeCode' => 'employee code', 'joinedAt' => 'joining date', 'departmentId' => 'department', 'managerId' => 'manager', 'monthlySalary' => 'monthly salary']);
        if (User::where('email', $candidate->email)->exists()) {
            $this->addError('employeeCode', 'A login with this candidate\'s email already exists.');

            return;
        }
        $employee = $recruitment->hire($candidate, [
            'employee_code' => $this->employeeCode, 'password' => $this->password, 'joined_at' => $this->joinedAt,
            'designation' => $this->designation ?: null, 'department_id' => $this->departmentId ?: null, 'manager_id' => $this->managerId ?: null,
            'monthly_salary' => $this->monthlySalary,
        ], $this->user());
        $this->showHire = false;
        $this->viewingId = null;
        $this->toast("{$employee->name} hired — login created and onboarding case opened.");
    }

    public function closeModal(): void
    {
        if ($this->showHire) {
            $this->showHire = false;

            return;
        }
        $this->showAdd = false;
        $this->viewingId = null;
    }

    public function render()
    {
        $candidates = Candidate::with(['jobOpening', 'interviews'])
            ->when($this->job, fn ($q) => $q->where('job_opening_id', $this->job))
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.trim($this->search).'%')->orWhere('email', 'like', '%'.trim($this->search).'%')))
            ->latest()->get();

        return $this->page('livewire.recruitment.candidates', [
            'board' => collect(Candidate::STAGES)->map(fn ($label, $stage) => $candidates->where('stage', $stage)->values()),
            'jobs' => JobOpening::orderByRaw("status = 'open' desc")->orderBy('title')->get(['id', 'title', 'status']),
            'viewing' => $this->viewingId ? Candidate::with(['jobOpening', 'interviews.interviewer', 'hiredEmployee'])->find($this->viewingId) : null,
            'interviewers' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'employees' => AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ], 'Candidates');
    }
}
