<?php

namespace App\Livewire\Support;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\SupportTicket;
use App\Services\SupportService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/** Help & Support for the employee: their own requests, and "New request". */
class MyRequests extends Component
{
    use InteractsWithHr, WithFileUploads, WithPagination;

    #[Url] public string $tab = 'active';
    public bool $showForm = false;
    public $category_id = '';
    public string $subject = '';
    public string $description = '';
    public array $attachments = [];

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function openForm(): void
    {
        $this->reset(['category_id', 'subject', 'description', 'attachments']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function closeModal(): void
    {
        $this->showForm = false;
    }

    public function removeAttachment(int $index): void
    {
        unset($this->attachments[$index]);
        $this->attachments = array_values($this->attachments);
    }

    public function save(SupportService $support)
    {
        \App\Services\ImpersonationService::block();
        $ticket = $support->create($this->requireEmployee(), $this->user(), ['category_id' => $this->category_id, 'subject' => $this->subject, 'description' => $this->description], $this->attachments);
        $this->showForm = false;
        session()->flash('toast', ['message' => 'Request '.$ticket->number().' sent. You will be notified when support replies.', 'type' => 'success']);

        return $this->redirectRoute('support.show', $ticket->id, navigate: false);
    }

    public function render(SupportService $support)
    {
        $employee = $this->requireEmployee();
        $base = SupportTicket::where('employee_id', $employee->id);
        $counts = ['active' => (clone $base)->whereIn('status', SupportService::ACTIVE)->count(), 'finished' => (clone $base)->whereIn('status', ['resolved', 'closed'])->count()];
        $counts['all'] = $counts['active'] + $counts['finished'];
        $tickets = (clone $base)->with(['category', 'assignee'])
            ->when($this->tab === 'active', fn ($q) => $q->whereIn('status', SupportService::ACTIVE))
            ->when($this->tab === 'finished', fn ($q) => $q->whereIn('status', ['resolved', 'closed']))
            ->orderByDesc('last_message_at')->orderByDesc('id')->paginate(15);

        return $this->page('livewire.support.my-requests', [
            'tickets' => $tickets,
            'unread' => $support->unreadIds($this->user(), $tickets->pluck('id'), false),
            'counts' => $counts,
            'categories' => $support->categories(),
            'statuses' => SupportService::STATUSES, 'statusTones' => SupportService::STATUS_TONES,
            'maxFiles' => SupportService::MAX_FILES,
        ], 'Help & Support');
    }
}
