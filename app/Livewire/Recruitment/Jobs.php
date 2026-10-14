<?php

namespace App\Livewire\Recruitment;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\Department;
use App\Models\JobOpening;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

class Jobs extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    #[Url] public string $status = 'open';
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $title = '';
    public string $departmentId = '';
    public string $location = '';
    public string $employmentType = 'full_time';
    public string $openings = '1';
    public string $description = '';
    public string $jobStatus = 'open';
    public string $hiringManagerId = '';
    public string $closesOn = '';

    public function edit(?int $id = null): void
    {
        $job = $id ? JobOpening::findOrFail($id) : null;
        $this->fill([
            'editingId' => $job?->id, 'title' => $job?->title ?? '', 'departmentId' => (string) ($job?->department_id ?? ''),
            'location' => (string) $job?->location, 'employmentType' => $job?->employment_type ?? 'full_time', 'openings' => (string) ($job?->openings ?? 1),
            'description' => (string) $job?->description, 'jobStatus' => $job?->status ?? 'open', 'hiringManagerId' => (string) ($job?->hiring_manager_id ?? ''),
            'closesOn' => (string) $job?->closes_on?->toDateString(),
        ]);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:160'],
            'departmentId' => ['nullable', 'exists:departments,id'],
            'location' => ['nullable', 'string', 'max:120'],
            'employmentType' => ['required', Rule::in(array_keys(JobOpening::TYPES))],
            'openings' => ['required', 'integer', 'min:1', 'max:500'],
            'description' => ['nullable', 'string', 'max:10000'],
            'jobStatus' => ['required', Rule::in(array_keys(JobOpening::STATUSES))],
            'hiringManagerId' => ['nullable', 'exists:users,id'],
            'closesOn' => ['nullable', 'date'],
        ], [], ['employmentType' => 'employment type', 'jobStatus' => 'status', 'hiringManagerId' => 'hiring manager', 'closesOn' => 'closing date']);
        $job = JobOpening::updateOrCreate(['id' => $this->editingId], [
            'title' => $this->title, 'department_id' => $this->departmentId ?: null, 'location' => $this->location ?: null,
            'employment_type' => $this->employmentType, 'openings' => (int) $this->openings, 'description' => $this->description ?: null,
            'status' => $this->jobStatus, 'hiring_manager_id' => $this->hiringManagerId ?: null, 'closes_on' => $this->closesOn ?: null,
        ] + ($this->editingId ? [] : ['created_by' => auth()->id()]));
        $audit->record($this->editingId ? 'job_opening.updated' : 'job_opening.created', $job);
        $this->showForm = false;
        $this->toast('Job opening saved.');
    }

    public function closeModal(): void
    {
        $this->showForm = false;
    }

    public function render()
    {
        $jobs = JobOpening::with(['department', 'hiringManager'])->withCount([
            'candidates', 'candidates as active_count' => fn ($q) => $q->whereNotIn('stage', ['hired', 'rejected']),
            'candidates as hired_count' => fn ($q) => $q->where('stage', 'hired'),
        ])->when($this->status, fn ($q) => $q->where('status', $this->status))->latest()->paginate(15);

        return $this->page('livewire.recruitment.jobs', [
            'jobs' => $jobs,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'managers' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ], 'Job Openings');
    }
}
