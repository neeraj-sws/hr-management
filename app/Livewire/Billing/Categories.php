<?php

namespace App\Livewire\Billing;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\BillingCategory;
use App\Models\BillingRevenue;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Income / expense categories used on revenue (like the old CMS categories). */
class Categories extends Component
{
    use InteractsWithHr;

    #[Url] public string $type = '';
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $categoryType = 'income';
    public string $color = '#2563eb';

    private function guard(): void { abort_unless($this->user()->canAny(['hr.manage', 'payroll.manage']), 403); }

    public function create(): void
    {
        $this->guard();
        $this->reset('editingId', 'name', 'color');
        $this->categoryType = $this->type ?: 'income';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->guard();
        $c = BillingCategory::findOrFail($id);
        $this->fill(['editingId' => $c->id, 'name' => $c->name, 'categoryType' => $c->type, 'color' => $c->color ?: '#2563eb']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->guard();
        $d = $this->validate([
            'name' => ['required', 'string', 'max:191'], 'categoryType' => ['required', Rule::in(array_keys(BillingCategory::TYPES))],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [], ['categoryType' => 'type']);
        $c = BillingCategory::updateOrCreate(['id' => $this->editingId], ['name' => $d['name'], 'type' => $d['categoryType'], 'color' => $d['color'] ?: null]);
        $audit->record($this->editingId ? 'billing.category_updated' : 'billing.category_created', $c);
        $this->showForm = false;
        $this->toast('Category saved.');
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        $this->guard();
        $c = BillingCategory::findOrFail($id);
        if (BillingRevenue::where('category_id', $c->id)->exists()) { $this->toast('Is category me revenue hai — delete nahi ho sakti.', 'error'); return; }
        $audit->record('billing.category_deleted', $c);
        $c->delete();
        $this->toast('Category deleted.');
    }

    public function closeModal(): void { $this->showForm = false; }

    public function render()
    {
        $this->guard();
        $categories = BillingCategory::when($this->type, fn ($q, $t) => $q->where('type', $t))->orderBy('type')->orderBy('name')->get();
        $used = BillingRevenue::whereNotNull('category_id')->selectRaw('category_id, count(*) c')->groupBy('category_id')->pluck('c', 'category_id');

        return $this->page('livewire.billing.categories', ['categories' => $categories, 'used' => $used], 'Categories');
    }
}
