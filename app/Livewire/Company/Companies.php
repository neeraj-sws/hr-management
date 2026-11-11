<?php

namespace App\Livewire\Company;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\Company;
use Livewire\Component;

/**
 * Master Admin only: list all companies, create new ones, edit existing ones.
 * A company login cannot reach this page (limitedCompanyId check).
 */
class Companies extends Component
{
    use InteractsWithHr;

    public bool $showForm = false;
    public ?int $editingId = null;

    public string $name = '';
    public string $code = '';
    public string $address = '';
    public string $phone = '';
    public string $email = '';
    public string $website = '';
    public string $ownerName = '';
    public bool $isActive = true;

    public function mount(): void
    {
        abort_if(\App\Support\CompanyAccess::isLimited($this->user()), 403, 'Only the Super Admin can manage companies.');
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
        $this->editingId = null;
    }

    public function edit(int $id): void
    {
        $company = Company::withoutGlobalScopes()->findOrFail($id);
        $this->editingId = $id;
        $this->name = $company->name;
        $this->code = $company->code ?? '';
        $this->address = $company->address ?? '';
        $this->phone = $company->phone ?? '';
        $this->email = $company->email ?? '';
        $this->website = $company->website ?? '';
        $this->ownerName = $company->owner_name ?? '';
        $this->isActive = (bool) $company->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_if(\App\Support\CompanyAccess::isLimited($this->user()), 403);
        $this->validate([
            'name'      => ['required', 'string', 'max:120'],
            'code'      => ['nullable', 'string', 'max:20', $this->editingId
                ? \Illuminate\Validation\Rule::unique('companies', 'code')->ignore($this->editingId)
                : \Illuminate\Validation\Rule::unique('companies', 'code')],
            'address'   => ['nullable', 'string', 'max:500'],
            'phone'     => ['nullable', 'string', 'max:30'],
            'email'     => ['nullable', 'email', 'max:120'],
            'website'   => ['nullable', 'string', 'max:255'],
            'ownerName' => ['nullable', 'string', 'max:120'],
        ], [], [
            'name' => 'company name', 'code' => 'company code', 'ownerName' => 'owner / authorised person',
        ]);

        $data = [
            'name'       => trim($this->name),
            'code'       => trim($this->code) ?: null,
            'address'    => trim($this->address) ?: null,
            'phone'      => trim($this->phone) ?: null,
            'email'      => trim($this->email) ?: null,
            'website'    => trim($this->website) ?: null,
            'owner_name' => trim($this->ownerName) ?: null,
            'is_active'  => $this->isActive,
        ];

        if ($this->editingId) {
            $company = Company::withoutGlobalScopes()->findOrFail($this->editingId);
            abort_if($company->is_main && ! $this->isActive, 422, 'The main company cannot be deactivated.');
            $company->update($data);
            $this->toast('Company updated.');
        } else {
            Company::withoutGlobalScopes()->create($data);
            $this->toast('Company created.');
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'code', 'address', 'phone', 'email', 'website', 'ownerName');
        $this->isActive = true;
        $this->resetValidation();
    }

    public function render()
    {
        $companies = Company::withoutGlobalScopes()->orderByDesc('is_main')->orderBy('name')->withCount('employees')->get();

        return $this->page('livewire.company.companies', ['companies' => $companies], 'Companies');
    }
}
