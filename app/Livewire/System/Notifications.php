<?php

namespace App\Livewire\System;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\ApprovalRequest;
use App\Services\ApprovalInbox;
use App\Support\NotificationPresenter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Notifications: filter chips, Today / Yesterday / Earlier, approve or reject requests right here. */
class Notifications extends Component
{
    use InteractsWithHr;

    /** all | action | attendance | daily_status | tasks | payroll */
    #[Url] public string $filter = 'all';
    #[Url(as: 'unread')] public bool $unreadOnly = false;
    public int $perPage = 20;

    /** The request being rejected (the small dialog), and the optional reason. */
    public ?int $rejectApproval = null;
    public ?string $rejectNotification = null;
    public string $rejectNote = '';

    public function updatedFilter(): void
    {
        $this->perPage = 20;
    }

    public function loadMore(): void
    {
        $this->perPage += 20;
    }

    public function markRead(string $id): void
    {
        $this->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();
    }

    /** A grouped row ("2 teammates checked in late") marks all of its notifications read. */
    public function markReadMany(array $ids): void
    {
        $this->user()->notifications()->whereIn('id', array_slice($ids, 0, 100))->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function markAllRead(): void
    {
        $this->user()->unreadNotifications->markAsRead();
        $this->toast('All notifications marked as read.');
    }

    public function approve(int $approvalId, string $notificationId, ApprovalInbox $inbox): void
    {
        $this->decide($inbox, $approvalId, $notificationId, true, null);
    }

    public function askReject(int $approvalId, string $notificationId): void
    {
        $this->rejectApproval = $approvalId;
        $this->rejectNotification = $notificationId;
        $this->rejectNote = '';
        $this->resetValidation();
    }

    public function confirmReject(ApprovalInbox $inbox): void
    {
        // A reason is required to reject (the same rule as the Approvals page and the app).
        $this->validate(['rejectNote' => ['required', 'string', 'max:1000']], ['rejectNote.required' => 'Add a reason when rejecting.']);
        if ($this->rejectApproval && $this->rejectNotification) {
            try {
                $inbox->decide($this->user(), ApprovalRequest::findOrFail($this->rejectApproval), false, trim($this->rejectNote) ?: null);
            } catch (ValidationException $e) {
                // e.g. an attendance correction needs a reason: say so in the dialog and keep it open.
                $this->addError('rejectNote', collect($e->errors())->flatten()->first() ?? 'This request could not be rejected.');

                return;
            }
            $this->user()->notifications()->whereKey($this->rejectNotification)->first()?->markAsRead();
            $this->toast('Rejected.');
        }
        $this->reset('rejectApproval', 'rejectNotification', 'rejectNote');
    }

    public function cancelReject(): void
    {
        $this->reset('rejectApproval', 'rejectNotification', 'rejectNote');
    }

    /** The same approval the Approvals page uses (permissions, no self-approval, audit, notifying the employee). */
    private function decide(ApprovalInbox $inbox, int $approvalId, string $notificationId, bool $approve, ?string $note): void
    {
        $approval = ApprovalRequest::findOrFail($approvalId);
        try {
            $inbox->decide($this->user(), $approval, $approve, $note);
        } catch (ValidationException $e) {
            $this->toast(collect($e->errors())->flatten()->first() ?? 'This request could not be decided.', 'error');

            return;
        }
        $this->user()->notifications()->whereKey($notificationId)->first()?->markAsRead();
        $this->toast($approve ? 'Approved.' : 'Rejected.');
    }

    public function render()
    {
        $presenter = new NotificationPresenter($this->user());
        if ($this->filter === 'action') {
            $all = collect($presenter->needsAction())->when($this->unreadOnly, fn ($c) => $c->where('unread', true))->values();
            $items = $all->take($this->perPage)->all();
            $hasMore = $all->count() > $this->perPage;
        } else {
            $chip = array_key_exists($this->filter, NotificationPresenter::CHIPS) ? $this->filter : null;
            $rows = $this->user()->notifications()
                ->when($chip, fn ($q) => $q->whereIn('data->kind', NotificationPresenter::kindsForChip($chip)))
                ->when($this->unreadOnly, fn ($q) => $q->whereNull('read_at'))
                ->latest()->orderBy('id')->limit($this->perPage + 1)->get();
            $hasMore = $rows->count() > $this->perPage;
            $items = $presenter->group($presenter->present($rows->take($this->perPage)));
        }

        return $this->page('livewire.system.notifications', [
            'days' => collect($items)->groupBy('day')->all(),
            'counts' => $presenter->counts(),
            'chips' => NotificationPresenter::CHIPS,
            'hasMore' => $hasMore,
        ], 'Notifications');
    }
}
