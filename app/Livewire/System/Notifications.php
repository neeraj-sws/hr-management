<?php

namespace App\Livewire\System;

use App\Livewire\Concerns\InteractsWithHr;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Notifications extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $filter = 'all';

    public function markRead(string $id): void
    {
        $this->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();
    }

    public function markAllRead(): void
    {
        $this->user()->unreadNotifications->markAsRead();
        $this->toast('All notifications marked as read.');
    }

    public function render()
    {
        $notifications = $this->user()->notifications()
            ->when($this->filter === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->latest()->paginate(20);

        return $this->page('livewire.system.notifications', ['notifications' => $notifications, 'unread' => $this->user()->unreadNotifications()->count()], 'Notifications');
    }
}
