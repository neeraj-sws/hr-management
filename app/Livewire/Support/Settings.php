<?php

namespace App\Livewire\Support;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\SupportCategory;
use App\Services\SupportService;
use Livewire\Component;

/** Help & Support settings (support.manage): the categories and how long a resolved request can be reopened. */
class Settings extends Component
{
    use InteractsWithHr;

    public ?int $editingId = null;
    public bool $showForm = false;
    public string $name = '';
    public string $description = '';
    public bool $is_restricted = false;
    public bool $is_active = true;
    public $sort_order = '';
    public $reopenDays = 7;

    public function mount(SupportService $support): void
    {
        $this->reopenDays = $support->reopenDays();
    }

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'description', 'is_restricted', 'sort_order']);
        $this->is_active = true;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $c = SupportCategory::findOrFail($id);
        $this->fill(['editingId' => $c->id, 'name' => $c->name, 'description' => (string) $c->description, 'is_restricted' => $c->is_restricted, 'is_active' => $c->is_active, 'sort_order' => (string) $c->sort_order]);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function closeModal(): void
    {
        $this->showForm = false;
    }

    public function save(SupportService $support): void
    {
        $support->saveCategory($this->editingId ? SupportCategory::findOrFail($this->editingId) : null, [
            'name' => $this->name, 'description' => $this->description, 'is_restricted' => $this->is_restricted, 'is_active' => $this->is_active,
            'sort_order' => $this->sort_order === '' ? null : $this->sort_order,
        ], $this->user());
        $this->showForm = false;
        $this->toast('Category saved.');
    }

    public function toggle(int $id, SupportService $support): void
    {
        $c = SupportCategory::findOrFail($id);
        $support->saveCategory($c, ['name' => $c->name, 'description' => $c->description, 'is_restricted' => $c->is_restricted, 'is_active' => ! $c->is_active, 'sort_order' => $c->sort_order], $this->user());
        $this->toast($c->is_active ? 'Category turned off: employees cannot pick it for new requests.' : 'Category turned on.');
    }

    public function saveReopen(SupportService $support): void
    {
        $this->validate(['reopenDays' => ['required', 'integer', 'min:0', 'max:365']], [], ['reopenDays' => 'days']);
        $support->setReopenDays((int) $this->reopenDays, $this->user());
        $this->toast('Saved.');
    }

    public function render()
    {
        return $this->page('livewire.support.settings', [
            'categories' => SupportCategory::withCount('tickets')->orderBy('sort_order')->orderBy('name')->get(),
        ], 'Support settings');
    }
}
