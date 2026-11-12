<?php

namespace App\Livewire\Support;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\SupportTicket;
use App\Services\SupportService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Livewire\Concerns\WithPaginationNoUrl as WithPagination;

/** Support desk (support.manage / support.handle): every request the person may see, in tabs, with search, filters and sorting. */
class Desk extends Component
{
    use InteractsWithHr, WithPagination;

    public const TABS = [
        'all' => 'All', 'new' => 'New / Unassigned', 'mine' => 'Assigned to me', 'in_progress' => 'In progress',
        'waiting' => 'Waiting for employee', 'resolved' => 'Resolved', 'closed' => 'Closed',
    ];
    public const SORTS = ['id', 'subject', 'priority', 'status', 'created_at', 'updated_at'];
    public const PER_PAGE = [15, 25, 50, 100];

    #[Url] public string $tab = 'all';
    #[Url] public string $search = '';
    #[Url] public string $category = '';
    #[Url] public string $priority = '';
    #[Url] public string $status = '';
    #[Url] public string $assignee = '';
    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public string $sort = 'updated_at';
    #[Url] public string $dir = 'desc';
    #[Url] public int $perPage = 15;

    public function mount(): void
    {
        if (! isset(self::TABS[$this->tab])) $this->tab = 'all';
    }

    public function updated(string $name): void
    {
        $this->resetPage();
    }

    public function showTab(string $tab): void
    {
        $this->tab = isset(self::TABS[$tab]) ? $tab : 'all';
        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, self::SORTS, true)) return;
        $this->dir = $this->sort === $column && $this->dir === 'asc' ? 'desc' : ($this->sort === $column ? 'asc' : ($column === 'subject' ? 'asc' : 'desc'));
        $this->sort = $column;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'category', 'priority', 'status', 'assignee', 'from', 'to']);
        $this->sort = 'updated_at';
        $this->dir = 'desc';
        $this->resetPage();
    }

    private function applyTab(Builder $q, string $tab): Builder
    {
        $me = $this->user()->id;

        return match ($tab) {
            'new' => $q->whereNull('assigned_to')->whereIn('status', SupportService::ACTIVE),
            'mine' => $q->where('assigned_to', $me)->whereIn('status', SupportService::ACTIVE),
            'in_progress' => $q->where('status', 'in_progress'),
            'waiting' => $q->where('status', 'waiting_for_employee'),
            'resolved' => $q->where('status', 'resolved'),
            'closed' => $q->where('status', 'closed'),
            default => $q,
        };
    }

    private function filtered(SupportService $support): Builder
    {
        $q = $support->staffScope(SupportTicket::query(), $this->user());
        $term = trim($this->search);
        if ($term !== '') {
            $id = SupportTicket::idFromSearch($term);
            $q->where(function ($q) use ($term, $id) {
                if ($id) $q->orWhere('support_tickets.id', $id);
                $q->orWhere('subject', 'like', "%{$term}%")
                    ->orWhereHas('employee', fn ($e) => $e->where('name', 'like', "%{$term}%")->orWhere('employee_code', 'like', "%{$term}%"));
            });
        }
        if ($this->category !== '') $q->where('category_id', (int) $this->category);
        if (isset(SupportService::PRIORITIES[$this->priority])) $q->where('priority', $this->priority);
        if (isset(SupportService::STATUSES[$this->status])) $q->where('status', $this->status);
        if ($this->assignee === 'none') $q->whereNull('assigned_to');
        elseif ($this->assignee !== '') $q->where('assigned_to', (int) $this->assignee);
        if ($this->from !== '' && strtotime($this->from)) $q->where('created_at', '>=', \Illuminate\Support\Carbon::parse($this->from)->startOfDay());
        if ($this->to !== '' && strtotime($this->to)) $q->where('created_at', '<=', \Illuminate\Support\Carbon::parse($this->to)->endOfDay());

        return $q;
    }

    public function render(SupportService $support)
    {
        $counts = [];
        foreach (array_keys(self::TABS) as $tab) $counts[$tab] = $this->applyTab($this->filtered($support), $tab)->count();

        $sort = in_array($this->sort, self::SORTS, true) ? $this->sort : 'updated_at';
        $dir = $this->dir === 'asc' ? 'asc' : 'desc';
        $query = $this->applyTab($this->filtered($support), $this->tab)->with(['employee', 'category', 'assignee']);
        if ($sort === 'priority') $query->orderByRaw("CASE priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END ".$dir);
        elseif ($sort === 'status') $query->orderByRaw("CASE status WHEN 'open' THEN 1 WHEN 'assigned' THEN 2 WHEN 'in_progress' THEN 3 WHEN 'waiting_for_employee' THEN 4 WHEN 'resolved' THEN 5 ELSE 6 END ".$dir);
        else $query->orderBy($sort, $dir);
        $tickets = $query->orderByDesc('id')->paginate(in_array($this->perPage, self::PER_PAGE, true) ? $this->perPage : 15);

        return $this->page('livewire.support.desk', [
            'tickets' => $tickets, 'counts' => $counts, 'tabs' => self::TABS,
            'unread' => $support->unreadIds($this->user(), $tickets->pluck('id'), true),
            'categories' => $support->categories(false)->filter(fn ($c) => ! $c->is_restricted || $this->user()->can('support.payroll')),
            'handlers' => $support->handlers(),
            'manage' => SupportService::canManage($this->user()),
            'statuses' => SupportService::STATUSES, 'statusTones' => SupportService::STATUS_TONES,
            'priorities' => SupportService::PRIORITIES, 'priorityTones' => SupportService::PRIORITY_TONES,
            'perPageOptions' => self::PER_PAGE,
        ], 'Support desk');
    }
}
