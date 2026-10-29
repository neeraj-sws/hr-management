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
    /** Attendance report: only one kind of day (present, late, on_leave, holiday, weekend, absent …). */
    #[Url] public string $day = '';
    /** Month picker: "2026-10" shows that whole month (this month: up to today); "custom" shows From / To. */
    #[Url] public string $month = '';
    /** Column the whole report is sorted by (all pages, not only the one on screen); dir 'asc' | 'desc'. */
    #[Url] public string $sort = '';
    #[Url] public string $dir = 'asc';

    /** A heading click: first ascending, next descending; page 1 then starts at the top of the whole list. */
    public function sortBy(string $column, ReportService $reports): void
    {
        if (! in_array($column, $reports->columns($this->type), true)) return;
        $this->dir = $this->sort === $column && $this->dir === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
        $this->resetPage();
    }

    public function mount(string $type, ReportService $reports): void
    {
        abort_unless($reports->canView($this->user(), $type), 403);
        $this->type = $type;
        $this->from = strtotime($this->from) ? $this->from : now()->startOfMonth()->toDateString();
        $this->to = strtotime($this->to) ? $this->to : today()->toDateString();
        if (preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->applyMonth();
        } elseif ($this->month !== 'custom') {
            // Opened with dates only: a whole month reads as that month, anything else as a custom range.
            $start = \Illuminate\Support\Carbon::parse($this->from);
            $wholeMonth = $start->isSameDay($start->copy()->startOfMonth()) && in_array($this->to, [$start->copy()->endOfMonth()->toDateString(), today()->toDateString()], true) && substr($this->to, 0, 7) === $start->format('Y-m');
            $this->month = $wholeMonth ? $start->format('Y-m') : 'custom';
        }
    }

    /** From / To for the picked month (this month runs up to today). */
    private function applyMonth(): void
    {
        $start = \Illuminate\Support\Carbon::parse($this->month.'-01');
        $end = $start->copy()->endOfMonth();
        $this->from = $start->toDateString();
        $this->to = ($end->isFuture() ? today() : $end)->toDateString();
    }

    public function updatedMonth(): void
    {
        if (preg_match('/^\d{4}-\d{2}$/', $this->month)) $this->applyMonth();
        $this->resetPage();
    }

    public function updated($property): void
    {
        if (in_array($property, ['from', 'to', 'employee', 'department', 'team', 'search', 'day'], true)) $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->resetPage();
        $this->reset('employee', 'department', 'team', 'search', 'day');
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = today()->toDateString();
        $this->month = now()->format('Y-m');
    }

    private function filters(): array
    {
        return array_filter(['from' => $this->from, 'to' => $this->to, 'employee_id' => $this->employee, 'department_id' => $this->department, 'team_id' => $this->team, 'search' => trim($this->search) ?: null, 'day_status' => $this->type === 'attendance' ? ($this->day ?: null) : null], fn ($v) => $v !== null && $v !== '');
    }

    public function render(ReportService $reports)
    {
        if ($this->to < $this->from) $this->to = $this->from;
        $filters = $this->filters();
        $rows = $reports->rows($this->user(), $this->type, $filters, self::PREVIEW_LIMIT + 1);
        $scopeIds = $reports->employeeIds($this->user(), $this->type, []);

        return $this->page('livewire.reports.report', [
            'label' => $reports->label($this->type),
            'columns' => $columns = $reports->columns($this->type),
            'headings' => collect($columns)->mapWithKeys(fn ($column) => [$column => $reports->heading($this->type, $column)])->all(),
            'rows' => $this->paginateCollection($this->sorted($rows->take(self::PREVIEW_LIMIT), $columns)->values(), self::PER_PAGE),
            'truncated' => $rows->count() > self::PREVIEW_LIMIT,
            'exportUrl' => route('hr.reports.export', ['type' => $this->type, ...$filters]),
            'exportUrlExcel' => route('hr.reports.export', ['type' => $this->type, 'format' => 'xlsx', ...$filters]),
            'types' => collect($reports->availableTypes($this->user()))->mapWithKeys(fn ($t) => [$t => $reports->label($t)]),
            'employees' => AttendanceEmployee::whereIn('id', $scopeIds)->orderByRaw('LENGTH(employee_code)')->orderBy('employee_code')->get(['id', 'name', 'employee_code']),
            'dayStatuses' => \App\Services\ReportService::DAY_STATUSES,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'teams' => Team::orderBy('name')->get(['id', 'name']),
            'usesEmployees' => $this->type !== 'audit',
            // Existing values to pick from in the Search box (actions for Audit, titles / names elsewhere).
            'suggestions' => $this->searchSuggestions($scopeIds),
        ], $reports->label($this->type).' report');
    }

    /** Sorts every row (before paging) the way the list headings sort: dates, times and numbers by value, empty cells last. */
    private function sorted(\Illuminate\Support\Collection $rows, array $columns): \Illuminate\Support\Collection
    {
        if (! in_array($this->sort, $columns, true)) return $rows;
        $dir = $this->dir === 'desc' ? -1 : 1;
        $keyed = $rows->values()->map(fn ($row, $i) => [$row, $i, self::sortValue($row[$this->sort] ?? null)])->all();
        usort($keyed, function ($x, $y) use ($dir) {
            [$a, $b] = [$x[2], $y[2]];
            if ($a[0] === 0 || $b[0] === 0) return $a[0] === $b[0] ? $x[1] <=> $y[1] : ($a[0] === 0 ? 1 : -1);
            $c = $a[0] !== $b[0] ? $a[0] <=> $b[0] : ($a[0] === 1 ? $a[1] <=> $b[1] : strnatcasecmp($a[1], $b[1]));

            return $c === 0 ? $x[1] <=> $y[1] : $dir * $c;
        });

        return collect(array_column($keyed, 0));
    }

    /** [kind, value]: kind 0 = empty, 1 = number (dates, times, durations, money), 2 = text. Same rules as public/js/table-sort.js. */
    private static function sortValue(mixed $value): array
    {
        if (is_int($value) || is_float($value)) return [1, (float) $value];
        if (is_bool($value)) return [1, (float) $value];
        if (! is_scalar($value)) return [0, ''];
        $text = trim(preg_replace('/\s+/', ' ', (string) $value));
        if ($text === '' || $text === '—' || $text === '-') return [0, ''];
        if (preg_match('/^[−-]?\s*(\d{1,3}):(\d{2})(?::(\d{2}))?\s*(am|pm)?\b/i', $text, $m)) {
            $h = (int) $m[1];
            if (! empty($m[4])) $h = $h % 12 + (strtolower($m[4]) === 'pm' ? 12 : 0);

            return [1, $h * 3600 + (int) $m[2] * 60 + (int) ($m[3] ?? 0)];
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m)) return [1, (float) gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1])];
        if (preg_match('#^(\d{1,2})[-/](\d{1,2})[-/](\d{4})#', $text, $m)) return [1, (float) gmmktime(0, 0, 0, (int) $m[2], (int) $m[1], (int) $m[3])];
        if (($t = strtotime($text)) !== false && preg_match('/^(?:[A-Za-z]{3},?\s+)?\d{1,2}\s+[A-Za-z]{3}|^[A-Za-z]{3}[a-z]*\s+\d{4}$/', $text)) return [1, (float) $t];
        if (preg_match('/^[₹$+−\-\d\s,.%]/u', $text) && preg_match('/^-?\d+(\.\d+)?/', str_replace(['₹', '$', ',', '%', '+', ' ', '−'], ['', '', '', '', '', '', '-'], $text), $m)) return [1, (float) $m[0]];

        return [2, mb_strtolower($text)];
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
