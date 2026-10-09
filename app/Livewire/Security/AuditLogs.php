<?php

namespace App\Livewire\Security;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AuditLog;
use App\Models\User;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class AuditLogs extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $search = '';
    #[Url] public ?int $actor = null;
    #[Url] public string $from = '';
    #[Url] public string $to = '';
    public ?int $viewingId = null;

    public function updated(): void
    {
        $this->resetPage();
    }

    public function closeModal(): void
    {
        $this->viewingId = null;
    }

    public function render()
    {
        $term = trim($this->search);
        $logs = AuditLog::with('actor')
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('action', 'like', "%{$term}%")->orWhere('subject_type', 'like', "%{$term}%")->orWhere('ip_address', 'like', "%{$term}%")))
            ->when($this->actor, fn ($q, $id) => $q->where('actor_id', $id))
            ->when(strtotime($this->from), fn ($q) => $q->where('created_at', '>=', $this->from.' 00:00:00'))
            ->when(strtotime($this->to), fn ($q) => $q->where('created_at', '<=', $this->to.' 23:59:59'))
            ->latest('id')->paginate(25);

        return $this->page('livewire.security.audit-logs', [
            'logs' => $logs,
            'actors' => User::whereIn('id', AuditLog::query()->select('actor_id')->distinct())->orderBy('name')->get(['id', 'name']),
            'viewing' => $this->viewingId ? AuditLog::with('actor')->find($this->viewingId) : null,
        ], 'Audit Logs');
    }
}
