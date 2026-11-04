<?php

namespace App\Livewire\Support;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\SupportTicketRead;
use App\Services\SupportService;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * One support request: the full chat (auto-refreshing), files, and for support staff the status, priority,
 * assignment, category, internal notes and the activity history. Employees see only their own request.
 */
class TicketShow extends Component
{
    use InteractsWithHr, WithFileUploads;

    public int $ticketId;
    public string $body = '';
    public array $files = [];
    public bool $internal = false;
    public string $replyStatus = '';
    public string $status = '';
    public string $priority = '';
    public $assignee = '';
    public $category = '';
    public ?int $hidingId = null;
    public string $hideReason = '';
    public bool $showReopen = false;
    public string $reopenReason = '';

    public function mount(SupportTicket $ticket, SupportService $support): void
    {
        abort_unless($support->canView($this->user(), $ticket), 403);
        $this->ticketId = $ticket->id;
        $this->syncFields($ticket);
    }

    private function ticket(): SupportTicket
    {
        $ticket = SupportTicket::with(['employee', 'category', 'assignee'])->findOrFail($this->ticketId);
        abort_unless(app(SupportService::class)->canView($this->user(), $ticket), 403);

        return $ticket;
    }

    private function syncFields(SupportTicket $ticket): void
    {
        $this->status = $ticket->status;
        $this->priority = $ticket->priority;
        $this->assignee = (string) ($ticket->assigned_to ?? '');
        $this->category = (string) $ticket->category_id;
    }

    private function staff(SupportTicket $ticket): bool
    {
        return app(SupportService::class)->staffCanView($this->user(), $ticket);
    }

    public function removeFile(int $index): void
    {
        unset($this->files[$index]);
        $this->files = array_values($this->files);
    }

    public function send(SupportService $support): void
    {
        \App\Services\ImpersonationService::block();
        $ticket = $this->ticket();
        $staff = $this->staff($ticket);
        $internal = $staff && $this->internal;
        $support->reply($ticket, $this->user(), $this->body, $this->files, $internal, $staff && $this->replyStatus !== '' ? $this->replyStatus : null);
        $this->reset(['body', 'files', 'internal', 'replyStatus']);
        $this->syncFields($ticket->refresh());
        $this->toast($internal ? 'Internal note added.' : 'Message sent.');
        $this->dispatch('support-sent');
    }

    public function saveStatus(SupportService $support): void
    {
        $ticket = $this->ticket();
        if ($support->setStatus($ticket, $this->user(), $this->status)) $this->toast('Status: '.SupportService::STATUSES[$this->status].'.');
        $this->syncFields($ticket->refresh());
    }

    public function savePriority(SupportService $support): void
    {
        $ticket = $this->ticket();
        if ($support->setPriority($ticket, $this->user(), $this->priority)) $this->toast('Priority: '.SupportService::PRIORITIES[$this->priority].'.');
        $this->syncFields($ticket->refresh());
    }

    public function saveAssignee(SupportService $support): void
    {
        $ticket = $this->ticket();
        $id = $this->assignee === '' || $this->assignee === null ? null : (int) $this->assignee;
        if ($support->assign($ticket, $this->user(), $id)) $this->toast($id ? 'Assigned.' : 'Unassigned.');
        $this->syncFields($ticket->refresh());
    }

    public function takeIt(SupportService $support): void
    {
        $ticket = $this->ticket();
        if ($support->assign($ticket, $this->user(), $this->user()->id)) $this->toast('The request is now with you.');
        $this->syncFields($ticket->refresh());
    }

    public function saveCategory(SupportService $support): void
    {
        $ticket = $this->ticket();
        if ($support->changeCategory($ticket, $this->user(), (int) $this->category)) $this->toast('Category changed.');
        $ticket->refresh()->load('category');
        $this->syncFields($ticket);
        // Moved to a category this person may not see: back to the desk.
        if (! $support->canView($this->user(), $ticket)) $this->redirectRoute('support.desk');
    }

    public function openHide(int $messageId): void
    {
        $this->hidingId = $messageId;
        $this->hideReason = '';
        $this->resetValidation();
    }

    public function hide(SupportService $support): void
    {
        $message = SupportMessage::where('ticket_id', $this->ticketId)->findOrFail($this->hidingId);
        $support->hideMessage($message, $this->user(), $this->hideReason);
        $this->hidingId = null;
        $this->toast('Message hidden from the employee. Recorded in the audit log.');
    }

    public function openReopen(): void
    {
        $this->showReopen = true;
        $this->reopenReason = '';
        $this->resetValidation();
    }

    public function reopen(SupportService $support): void
    {
        $support->reopen($this->ticket(), $this->user(), $this->reopenReason);
        $this->showReopen = false;
        $this->syncFields($this->ticket());
        $this->toast('Request reopened. Support has been told.');
    }

    public function closeModal(): void
    {
        $this->hidingId = null;
        $this->showReopen = false;
    }

    public function render(SupportService $support)
    {
        $ticket = $this->ticket();
        $user = $this->user();
        $staff = $this->staff($ticket);
        $support->markRead($ticket, $user);
        $ownerId = (int) $ticket->employee?->user_id;
        $reads = SupportTicketRead::where('ticket_id', $ticket->id)->get();

        return $this->page('livewire.support.ticket-show', [
            'ticket' => $ticket, 'staff' => $staff, 'manage' => $staff && SupportService::canManage($user),
            'messages' => $support->visibleMessages($ticket, $staff),
            'employeeRead' => $reads->firstWhere('user_id', $ownerId)?->last_read_at,
            'staffRead' => $reads->where('user_id', '!=', $ownerId)->max('last_read_at'),
            'ownerId' => $ownerId,
            'history' => $staff ? $support->history($ticket) : collect(),
            'handlers' => $staff ? $support->handlers($ticket) : collect(),
            'categories' => $staff ? $support->categories(false)->filter(fn ($c) => $c->id === $ticket->category_id || ($c->is_active && (! $c->is_restricted || $user->can('support.payroll')))) : collect(),
            'canReopen' => ! $staff && $support->isOwner($user, $ticket) && $support->canReopen($ticket),
            'reopenUntil' => $support->reopenUntil($ticket),
            'support' => $support,
            'statuses' => SupportService::STATUSES, 'statusTones' => SupportService::STATUS_TONES,
            'priorities' => SupportService::PRIORITIES, 'priorityTones' => SupportService::PRIORITY_TONES,
            'maxFiles' => SupportService::MAX_FILES,
        ], $ticket->number().' · Help & Support');
    }
}
