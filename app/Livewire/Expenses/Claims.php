<?php

namespace App\Livewire\Expenses;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\ApprovalRequest;
use App\Models\ExpenseClaim;
use App\Services\ApprovalInbox;
use App\Services\ExpenseService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Claims extends Component
{
    use InteractsWithHr, WithFileUploads, WithPagination;

    #[Url] public string $tab = '';
    #[Url] public string $status = '';
    public bool $showForm = false;
    public string $category = 'Travel';
    public string $spentOn = '';
    public string $amount = '';
    public string $description = '';
    public $receipt = null;
    public ?int $decidingId = null;
    public string $decisionNote = '';

    public function mount(): void
    {
        if (! in_array($this->tab, ['mine', 'approvals', 'all'], true)) $this->tab = ! $this->ownEmployee() || ($this->canApprove() && app(ApprovalInbox::class)->pending($this->user(), ExpenseClaim::class)->isNotEmpty()) ? 'approvals' : 'mine';
        if ($this->tab !== 'mine' && ! $this->canApprove()) $this->tab = 'mine';
    }

    /** Expense administrators, approvers, and team leads for their own team's claims. */
    private function canApprove(): bool
    {
        return $this->user()->canAny(['expenses.manage', 'approvals.manage']) || ApprovalInbox::isTeamLead($this->user());
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->requireEmployee();
        $this->reset('amount', 'description', 'receipt');
        $this->category = 'Travel';
        $this->spentOn = today()->toDateString();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function submit(ExpenseService $expenses): void
    {
        $this->validate([
            'category' => ['required', Rule::in(ExpenseClaim::CATEGORIES)],
            'spentOn' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'receipt' => ExpenseService::RECEIPT_RULES,
        ], [], ['spentOn' => 'date', 'receipt' => 'receipt']);
        $expenses->submit($this->requireEmployee(), ['category' => $this->category, 'spent_on' => $this->spentOn, 'amount' => $this->amount, 'description' => $this->description], $this->receipt);
        $this->showForm = false;
        $this->toast('Expense claim submitted. Your manager and the expense administrators were notified.');
    }

    public function cancel(int $id, ExpenseService $expenses): void
    {
        $expenses->cancel(ExpenseClaim::findOrFail($id), $this->requireEmployee());
        $this->toast('Claim cancelled. Your approvers were told.');
    }

    public function openDecision(int $id): void
    {
        $this->fill(['decidingId' => $id, 'decisionNote' => '']);
        $this->resetValidation();
    }

    public function decide(bool $approve, ApprovalInbox $inbox): void
    {
        $this->validate(['decisionNote' => [$approve ? 'nullable' : 'required', 'string', 'max:1000']], ['decisionNote.required' => 'Add a reason when rejecting.']);
        $approval = ApprovalRequest::where('approvable_type', ExpenseClaim::class)->where('approvable_id', $this->decidingId)->where('status', 'pending')->firstOrFail();
        $inbox->decide($this->user(), $approval, $approve, $this->decisionNote ?: null);
        $this->decidingId = null;
        $this->toast($approve ? 'Claim approved — it will be paid with the next payroll.' : 'Claim rejected.');
    }

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->decidingId = null;
    }

    public function render(ApprovalInbox $inbox)
    {
        $user = $this->user();
        $own = $this->ownEmployee();
        $query = ExpenseClaim::with(['employee', 'decider'])->latest('id'); // newest claim first
        if ($this->tab === 'mine') $query->where('employee_id', $own?->id ?? 0);
        elseif ($this->tab === 'approvals') $query->whereIn('id', $inbox->pending($user, ExpenseClaim::class)->pluck('approvable_id')->all() ?: [0]);
        else $query->when(! $user->canAny(['expenses.manage', 'hr.manage']), fn ($q) => $q->whereIn('employee_id', $this->teamEmployeeIds()->all() ?: [0]));
        if ($this->status === 'paid') $query->where('status', 'approved')->whereNotNull('payroll_item_id');
        elseif ($this->status === 'approved') $query->where('status', 'approved')->whereNull('payroll_item_id');
        elseif ($this->status) $query->where('status', $this->status);

        $mine = $own ? ExpenseClaim::where('employee_id', $own->id) : null;

        return $this->page('livewire.expenses.claims', [
            'claims' => $query->paginate(15),
            'own' => $own,
            'canApprove' => $this->canApprove(),
            'pendingCount' => $this->canApprove() ? $inbox->pending($user, ExpenseClaim::class)->count() : 0,
            'categories' => ExpenseClaim::CATEGORIES,
            'totals' => $mine ? [
                'pending' => (float) (clone $mine)->where('status', 'pending')->sum('amount'),
                'to_be_paid' => (float) (clone $mine)->where('status', 'approved')->whereNull('payroll_item_id')->sum('amount'),
                'paid_year' => (float) (clone $mine)->where('status', 'approved')->whereNotNull('payroll_item_id')->whereYear('spent_on', today()->year)->sum('amount'),
            ] : null,
            'deciding' => $this->decidingId ? ExpenseClaim::with('employee')->find($this->decidingId) : null,
        ], 'Expense Claims');
    }
}
