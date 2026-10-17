<?php

namespace App\Livewire\Reports;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\Department;
use App\Models\Team;
use App\Services\ReportService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class Report extends Component
{
    use InteractsWithHr, \Livewire\WithPagination;

    /** Rows read for the on-screen report (paged 25 at a time); the export always has every row. */
    public const PREVIEW_LIMIT = 5000;
    public const PER_PAGE = 25;

    #[Locked] public string $type = '';
    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public ?int $employee = null;
    #[Url] public ?int $department = null;
    #[Url] public ?int $team = null;
    #[Url] public string $search = '';

    public function mount(string $type, ReportService $reports): void
    {
        abort_unless($reports->canView($this->user(), $type), 403);
        $this->type = $type;
        $this->from = strtotime($this->from) ? $this->from : now()->startOfMonth()->toDateString();
        $this->to = strtotime($this->to) ? $this->to : today()->toDateString();
    }

    public function updated($property): void
    {
        if (in_array($property, ['from', 'to', 'employee', 'department', 'team', 'search'], true)) $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->resetPage();
        $this->reset('employee', 'department', 'team', 'search');
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = today()->toDateString();
    }

    private function filters(): array
    {
        return array_filter(['from' => $this->from, 'to' => $this->to, 'employee_id' => $this->employee, 'department_id' => $this->department, 'team_id' => $this->team, 'search' => trim($this->search) ?: null], fn ($v) => $v !== null && $v !== '');
    }

    public function render(ReportService $reports)
    {
        if ($this->to < $this->from) $this->to = $this->from;
        $filters = $this->filters();
        $rows = $reports->rows($this->user(), $this->type, $filters, self::PREVIEW_LIMIT + 1);
        $scopeIds = $reports->employeeIds($this->user(), $this->type, []);

        return $this->page('livewire.reports.report', [
            'label' => $reports->label($this->type),
            'columns' => $reports->columns($this->type),
            'rows' => $this->paginateCollection($rows->take(self::PREVIEW_LIMIT)->values(), self::PER_PAGE),
            'truncated' => $rows->count() > self::PREVIEW_LIMIT,
            'exportUrl' => route('hr.reports.export', ['type' => $this->type, ...$filters]),
            'exportUrlExcel' => route('hr.reports.export', ['type' => $this->type, 'format' => 'xlsx', ...$filters]),
            'types' => collect($reports->availableTypes($this->user()))->mapWithKeys(fn ($t) => [$t => $reports->label($t)]),
            'employees' => AttendanceEmployee::whereIn('id', $scopeIds)->orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'teams' => Team::orderBy('name')->get(['id', 'name']),
            'usesEmployees' => $this->type !== 'audit',
            // Existing values to pick from in the Search box (actions for Audit, titles / names elsewhere).
            'suggestions' => $this->searchSuggestions($scopeIds),
        ], $reports->label($this->type).' report');
    }

    /** @return array<int, string> */
    private function searchSuggestions($scopeIds): array
    {
        return match ($this->type) {
            'audit' => \App\Models\AuditLog::query()->distinct()->orderBy('action')->limit(500)->pluck('action')->all(),
            'tasks' => \App\Models\Task::whereIn('assignee_id', $scopeIds)->distinct()->orderBy('title')->limit(500)->pluck('title')->all(),
            'projects' => \App\Models\Project::orderBy('name')->limit(500)->pluck('name')->all(),
            default => AttendanceEmployee::whereIn('id', $scopeIds)->orderBy('name')->get(['name', 'employee_code'])->flatMap(fn ($e) => [$e->name, $e->employee_code])->filter()->unique()->values()->all(),
        };
    }

}
