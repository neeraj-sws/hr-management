<?php

namespace App\Livewire\Concerns;

use App\Models\AttendanceEmployee;
use App\Models\TrainingCourse;
use App\Services\AuditLogger;
use App\Services\TrainingMentorService;
use Illuminate\Validation\Rule;

/**
 * The one course form (Training > Courses and Training > Course fees): name, duration, the fee in installments and
 * at once, the usual number of installments, and the topics in teaching order with the mentors who can teach each.
 */
trait EditsTrainingCourse
{
    public bool $showCourseForm = false;
    public ?int $editingCourseId = null;
    public string $title = '';
    public string $description = '';
    public string $category = '';
    public string $provider = '';
    public string $durationHours = '';
    /** Fee in installments (the full fee). Empty = free staff training. */
    public string $fee = '';
    /** Fee if paid at once; the difference is the one-time discount. */
    public string $atOnceFee = '';
    public string $oneTimeDiscount = '';
    public string $defaultInstallments = '';
    public string $durationValue = '';
    public string $durationUnit = 'months';
    public string $durationLabel = '';
    public string $materialUrl = '';
    public bool $isMandatory = false;
    public bool $isActive = true;
    /** @var list<array{id: int|null, title: string, mentor_ids: list<string>}> */
    public array $topics = [];

    protected function canEditCourses(): bool
    {
        return $this->user()->canAny(['training.manage', 'hr.manage']);
    }

    public function editCourse(?int $id = null): void
    {
        abort_unless($this->canEditCourses(), 403);
        $c = $id ? TrainingCourse::with('topics.mentors:id')->findOrFail($id) : null;
        $value = $c?->duration_value;
        $unit = $c?->duration_unit;
        if (! $value && $c?->duration_label && preg_match('/^\s*(\d{1,3})\s*(day|week|month|year)s?\s*$/i', $c->duration_label, $m)) [$value, $unit] = [(int) $m[1], strtolower($m[2]).'s'];
        $this->fill([
            'editingCourseId' => $c?->id, 'title' => (string) $c?->title, 'description' => (string) $c?->description, 'category' => (string) $c?->category, 'provider' => (string) $c?->provider,
            'durationHours' => $c?->duration_hours !== null ? (string) (float) $c->duration_hours : '',
            'fee' => $c?->fee !== null ? (string) (float) $c->fee : '',
            'atOnceFee' => $c?->fee !== null && (float) $c->one_time_discount > 0 ? (string) $c->atOnceFee() : '',
            'oneTimeDiscount' => $c && (float) $c->one_time_discount > 0 ? (string) (float) $c->one_time_discount : '',
            'defaultInstallments' => $c?->default_installments ? (string) $c->default_installments : '',
            'durationValue' => $value ? (string) $value : '', 'durationUnit' => $unit ?: 'months',
            'durationLabel' => $value ? '' : (string) $c?->duration_label,
            'materialUrl' => (string) $c?->material_url, 'isMandatory' => (bool) $c?->is_mandatory, 'isActive' => $c?->is_active ?? true,
            'topics' => $c ? $c->topics->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'mentor_ids' => $t->mentors->pluck('id')->map(fn ($i) => (string) $i)->all()])->all() : [],
        ]);
        $this->resetValidation();
        $this->showCourseForm = true;
    }

    public function addTopic(): void
    {
        $this->topics[] = ['id' => null, 'title' => '', 'mentor_ids' => []];
    }

    public function removeTopic(int $i): void
    {
        unset($this->topics[$i]);
        $this->topics = array_values($this->topics);
    }

    public function moveTopic(int $i, int $by): void
    {
        $j = $i + $by;
        if (! isset($this->topics[$i], $this->topics[$j])) return;
        [$this->topics[$i], $this->topics[$j]] = [$this->topics[$j], $this->topics[$i]];
    }

    public function addTopicMentor(int $i, $employeeId): void
    {
        if (! isset($this->topics[$i]) || ! $employeeId) return;
        $ids = $this->topics[$i]['mentor_ids'] ?? [];
        if (! in_array((string) $employeeId, $ids, true)) $ids[] = (string) $employeeId;
        $this->topics[$i]['mentor_ids'] = $ids;
    }

    public function removeTopicMentor(int $i, $employeeId): void
    {
        if (! isset($this->topics[$i])) return;
        $this->topics[$i]['mentor_ids'] = array_values(array_filter($this->topics[$i]['mentor_ids'] ?? [], fn ($id) => (string) $id !== (string) $employeeId));
    }

    public function saveCourse(AuditLogger $audit, TrainingMentorService $mentors): void
    {
        abort_unless($this->canEditCourses(), 403);
        $fee = $this->fee === '' ? null : (float) $this->fee;
        $this->validate([
            'title' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:60'], 'provider' => ['nullable', 'string', 'max:120'],
            'durationHours' => ['nullable', 'numeric', 'min:0', 'max:1000'], 'materialUrl' => ['nullable', 'url:http,https', 'max:500'],
            'fee' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'atOnceFee' => ['nullable', 'numeric', 'min:0', 'lte:'.($fee ?? 0)],
            'oneTimeDiscount' => ['nullable', 'numeric', 'min:0', 'lte:'.($fee ?? 0)],
            'defaultInstallments' => ['nullable', 'integer', 'min:1', 'max:36'],
            'durationValue' => ['nullable', 'integer', 'min:1', 'max:999'], 'durationUnit' => [Rule::in(array_keys(TrainingCourse::DURATION_UNITS))],
            'durationLabel' => ['nullable', 'string', 'max:60'],
            'topics' => ['array', 'max:30'], 'topics.*.title' => ['required', 'string', 'max:120'], 'topics.*.mentor_ids' => ['array'], 'topics.*.mentor_ids.*' => ['integer', 'exists:attendance_employees,id'],
        ], ['atOnceFee.lte' => 'The fee at once cannot be more than the fee in installments.', 'oneTimeDiscount.lte' => 'The discount cannot be more than the fee.', 'topics.*.title.required' => 'Give the topic a name, or remove it.'],
            ['title' => 'course name', 'durationHours' => 'hours', 'materialUrl' => 'material link', 'oneTimeDiscount' => 'discount', 'atOnceFee' => 'fee at once', 'defaultInstallments' => 'installments', 'durationValue' => 'duration']);
        // Two prices: what is paid in installments and at once; the difference is the discount for paying at once.
        $discount = $fee === null ? 0 : ($this->atOnceFee !== '' ? round($fee - (float) $this->atOnceFee, 2) : (float) ($this->oneTimeDiscount ?: 0));
        $value = $this->durationValue === '' ? null : (int) $this->durationValue;
        $label = $value ? $value.' '.($value === 1 ? rtrim($this->durationUnit, 's') : $this->durationUnit) : ($this->durationLabel ?: null);
        $course = TrainingCourse::updateOrCreate(['id' => $this->editingCourseId], [
            'title' => trim($this->title), 'description' => $this->description ?: null, 'category' => $this->category ?: null, 'provider' => $this->provider ?: null,
            'duration_hours' => $this->durationHours === '' ? null : $this->durationHours,
            'fee' => $fee, 'one_time_discount' => max(0, $discount), 'default_installments' => $this->defaultInstallments === '' ? null : (int) $this->defaultInstallments,
            'duration_value' => $value, 'duration_unit' => $value ? $this->durationUnit : null, 'duration_label' => $label,
            'material_url' => $this->materialUrl ?: null, 'is_mandatory' => $this->isMandatory, 'is_active' => $this->isActive,
        ] + ($this->editingCourseId ? [] : ['created_by' => auth()->id()]));
        $mentors->saveCourseTopics($course, $this->topics);
        $audit->record($this->editingCourseId ? 'training.course_updated' : 'training.course_created', $course, ['fee' => $course->fee !== null ? (float) $course->fee : null, 'topics' => $course->topics()->pluck('title')->join(', ')]);
        $this->showCourseForm = false;
        $this->toast('Course saved.');
    }

    /** Employees who can be mentors: everyone active except trainees. */
    protected function mentorOptions()
    {
        return AttendanceEmployee::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('employment_type')->orWhere('employment_type', '!=', \App\Services\EmployeeService::TRAINEE))
            ->orderBy('name')->get(['id', 'name', 'employee_code']);
    }
}
