<?php

namespace App\Livewire\Payroll;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\Payslip;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Payslips extends Component
{
    use InteractsWithHr, WithPagination;

    #[Url] public string $search = '';
    #[Url] public string $scope = 'mine';

    public function mount(): void
    {
        $canAll = $this->user()->canAny(['payroll.manage', 'payroll.approve']);
        if (! $canAll || ! in_array($this->scope, ['mine', 'all'], true)) $this->scope = 'mine';
        if ($canAll && ! $this->ownEmployee()) $this->scope = 'all';
    }

    public function render()
    {
        $canAll = $this->user()->canAny(['payroll.manage', 'payroll.approve']);
        $payslips = Payslip::with(['employee', 'item.run', 'item.payments'])
            ->when($this->scope === 'mine' || ! $canAll, fn ($q) => $q->where('employee_id', $this->ownEmployee()?->id ?? 0))
            ->when($this->scope === 'all' && trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.trim($this->search).'%')->orWhereHas('employee', fn ($e) => $e->where('name', 'like', '%'.trim($this->search).'%')->orWhere('employee_code', 'like', '%'.trim($this->search).'%'))))
            ->latest('issued_at')->paginate(15);

        return $this->page('livewire.payroll.payslips', ['payslips' => $payslips, 'canAll' => $canAll, 'own' => $this->ownEmployee()], 'Payslips');
    }
}
