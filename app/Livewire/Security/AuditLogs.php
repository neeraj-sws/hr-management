<?php

namespace App\Livewire\Security;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AuditLog;
use App\Models\User;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Audit log, grouped by category (Attendance, Payroll, Employee, Tasks, Leave, Assets, Expenses, System Activity). */
class AuditLogs extends Component
{
    use InteractsWithHr, WithPagination;

    public const SORTS = ['newest' => 'Newest first', 'oldest' => 'Oldest first', 'action' => 'Action (A–Z)', 'actor' => 'Actor (A–Z)'];

    #[Url] public string $category = '';
    #[Url] public string $search = '';
    #[Url] public ?int $actor = null;
    #[Url] public string $record = '';
    /** From an employee's profile: every change recorded about that person. */
    #[Url] public ?int $employee = null;
    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public string $sort = 'newest';
    #[Url] public int $perPage = 25;
    public ?int $viewingId = null;

    public function updated($property): void
    {
        if ($property !== 'viewingId') $this->resetPage();
    }

    public function setCategory(string $category): void
    {
        $this->category = isset(AuditLog::CATEGORIES[$category]) ? $category : '';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('category', 'search', 'actor', 'record', 'employee', 'from', 'to', 'sort', 'perPage');
        $this->resetPage();
    }

    public function closeModal(): void
    {
        $this->viewingId = null;
    }

    /** Every filter except the category, so category counts reflect the other filters. */
    private function filtered()
    {
        $term = trim($this->search);

        return AuditLog::query()
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('action', 'like', "%{$term}%")
                ->orWhere('subject_type', 'like', "%{$term}%")
                ->orWhere('ip_address', 'like', "%{$term}%")
                ->orWhere('metadata', 'like', "%{$term}%")
                ->orWhere('subject_id', ctype_digit($term) ? (int) $term : -1)
                ->orWhereHas('actor', fn ($a) => $a->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"))))
            ->when($this->actor, fn ($q, $id) => $q->where('actor_id', $id))
            ->when($this->record !== '', fn ($q) => $q->where('subject_type', 'like', '%\\'.$this->record))
            ->when($this->employee, fn ($q, $id) => $q->where('subject_type', \App\Models\AttendanceEmployee::class)->where('subject_id', $id))
            ->when(strtotime($this->from), fn ($q) => $q->where('created_at', '>=', $this->from.' 00:00:00'))
            ->when(strtotime($this->to), fn ($q) => $q->where('created_at', '<=', $this->to.' 23:59:59'));
    }

    public function render()
    {
        $perPage = in_array($this->perPage, [25, 50, 100], true) ? $this->perPage : 25;
        $logs = $this->filtered()->with('actor')
            ->when($this->category !== '', fn ($q) => $q->category($this->category))
            ->when($this->sort === 'oldest', fn ($q) => $q->orderBy('id'))
            ->when($this->sort === 'action', fn ($q) => $q->orderBy('action')->orderByDesc('id'))
            ->when($this->sort === 'actor', fn ($q) => $q->orderBy(User::select('name')->whereColumn('users.id', 'audit_logs.actor_id'))->orderByDesc('id'))
            ->when(! in_array($this->sort, ['oldest', 'action', 'actor'], true), fn ($q) => $q->orderByDesc('id'))
            ->paginate($perPage);

        $counts = collect(AuditLog::CATEGORIES)->mapWithKeys(fn ($definition, $key) => [$key => $this->filtered()->category($key)->count()]);

        return $this->page('livewire.security.audit-logs', [
            'logs' => $logs,
            'counts' => $counts,
            'total' => $counts->sum(),
            'categories' => AuditLog::CATEGORIES,
            'actors' => User::whereIn('id', AuditLog::query()->select('actor_id')->distinct())->orderBy('name')->get(['id', 'name']),
            'records' => AuditLog::query()->whereNotNull('subject_type')->distinct()->pluck('subject_type')->map(fn ($type) => class_basename($type))->unique()->sort()->values(),
            'employeeName' => $this->employee ? \App\Models\AttendanceEmployee::whereKey($this->employee)->value('name') : null,
            'viewing' => $this->viewingId ? AuditLog::with('actor')->find($this->viewingId) : null,
        ], 'Audit Logs');
    }
}
