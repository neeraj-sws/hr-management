<?php

namespace App\Livewire\Support;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\SupportTicket;
use App\Services\SupportService;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Help & Support reports (support.manage): totals, resolution time, by category, per handler and per employee. */
class Reports extends Component
{
    use InteractsWithHr;

    #[Url] public string $from = '';
    #[Url] public string $to = '';
    #[Url] public string $category = '';
    #[Url] public string $status = '';
    #[Url] public string $assignee = '';
    #[Url] public string $employee = '';

    public function mount(): void
    {
        $this->from = $this->from ?: today()->startOfMonth()->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function resetFilters(): void
    {
        $this->from = today()->startOfMonth()->toDateString();
        $this->to = today()->toDateString();
        $this->reset(['category', 'status', 'assignee', 'employee']);
    }

    public function showEmployee(?int $id = null): void
    {
        $this->employee = $id ? (string) $id : '';
    }

    public function render(SupportService $support)
    {
        $filters = ['from' => $this->from, 'to' => $this->to, 'category' => $this->category, 'status' => $this->status, 'assignee' => $this->assignee];
        $report = $support->report($this->user(), $filters);
        $person = $this->employee !== '' ? AttendanceEmployee::find((int) $this->employee) : null;
        $history = $person
            ? $support->staffScope(SupportTicket::query(), $this->user())->where('employee_id', $person->id)->with(['category', 'assignee'])->latest('id')->limit(100)->get()
            : collect();

        return $this->page('livewire.support.reports', [
            'report' => $report, 'person' => $person, 'history' => $history,
            'categories' => $support->categories(false)->filter(fn ($c) => ! $c->is_restricted || $this->user()->can('support.payroll')),
            'handlers' => $support->handlers(),
            'statuses' => SupportService::STATUSES, 'statusTones' => SupportService::STATUS_TONES, 'priorities' => SupportService::PRIORITIES,
        ], 'Support reports');
    }
}
