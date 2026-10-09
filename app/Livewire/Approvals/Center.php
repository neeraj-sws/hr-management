<?php

namespace App\Livewire\Approvals;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\ApprovalRequest;
use App\Services\ApprovalInbox;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Approval Center, Pending Approvals and Approval History share this page. */
class Center extends Component
{
    use InteractsWithHr;

    #[Locked] public string $mode = 'center';
    #[Url] public string $type = '';
    public ?int $decidingId = null;
    public string $note = '';

    public function mount(): void
    {
        $this->mode = match (request()->route()?->getName()) {
            'approvals.pending' => 'pending',
            'approvals.history' => 'history',
            default => 'center',
        };
        if ($this->type && ! array_key_exists($this->type, ApprovalInbox::TYPES)) $this->type = '';
    }

    public function open(int $id): void
    {
        $this->decidingId = $id;
        $this->note = '';
        $this->resetValidation();
    }

    public function decide(bool $approve, ApprovalInbox $inbox): void
    {
        $this->validate(['note' => [$approve ? 'nullable' : 'required', 'string', 'max:1000']], ['note.required' => 'Add a reason when rejecting.']);
        $approval = ApprovalRequest::findOrFail($this->decidingId);
        $inbox->decide($this->user(), $approval, $approve, $this->note ?: null);
        $this->decidingId = null;
        $this->toast($approve ? 'Approved.' : 'Rejected.');
    }

    public function quickApprove(int $id, ApprovalInbox $inbox): void
    {
        $inbox->decide($this->user(), ApprovalRequest::findOrFail($id), true);
        $this->toast('Approved.');
    }

    public function closeModal(): void
    {
        $this->decidingId = null;
    }

    public function render(ApprovalInbox $inbox)
    {
        $user = $this->user();
        $pending = $inbox->pending($user);
        $title = ['center' => 'Approval Center', 'pending' => 'Pending Approvals', 'history' => 'Approval History'][$this->mode];
        $deciding = $this->decidingId ? ApprovalRequest::with('approvable')->find($this->decidingId) : null;

        return $this->page('livewire.approvals.center', [
            'inbox' => $inbox,
            'title' => $title,
            'byType' => $pending->groupBy('approvable_type'),
            'pending' => $this->type ? $pending->where('approvable_type', $this->type)->values() : $pending,
            'history' => $this->mode === 'history' ? $inbox->history($user, $this->type ?: null) : collect(),
            'recent' => $this->mode === 'center' ? $inbox->history($user, null, 8) : collect(),
            'types' => array_intersect_key(ApprovalInbox::TYPES, array_flip($inbox->typesFor($user))),
            'deciding' => $deciding && $deciding->approvable && $inbox->canDecide($user, $deciding) ? $deciding : null,
        ], $title);
    }
}
