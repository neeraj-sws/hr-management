<?php

namespace App\Livewire\Payroll;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\Payslip;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Payslips extends Component
{
    use InteractsWithHr, WithPagination, \Livewire\WithFileUploads;

    #[Url] public string $search = '';
    #[Url] public string $scope = 'mine';

    public function mount(): void
    {
        $canAll = $this->user()->canAny(['payroll.manage', 'payroll.approve']);
        if (! $canAll || ! in_array($this->scope, ['mine', 'all'], true)) $this->scope = 'mine';
        if ($canAll && ! $this->ownEmployee()) $this->scope = 'all';
    }

    /** Payslip requests: the employee asks for a month; payroll staff answer the ones for older months. */
    public string $reqMonth = '';
    public string $reqNote = '';
    public ?int $rejectingId = null;
    public string $rejectNote = '';
    public ?int $uploadingId = null;
    public $uploadFile = null;

    public function requestPayslip(\App\Services\PayslipRequestService $service): void
    {
        $this->validate(['reqMonth' => ['required', 'date_format:Y-m'], 'reqNote' => ['nullable', 'string', 'max:1000']], [], ['reqMonth' => 'month', 'reqNote' => 'note']);
        try {
            $made = $service->request($this->requireEmployee(), $this->reqMonth, $this->reqNote, $this->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['reqMonth' => collect($e->errors())->flatten()->first()]);
        }
        $this->reset('reqMonth', 'reqNote');
        $this->toast($made->status === 'sent' ? 'That payslip is ready: open it under My payslip requests.' : 'Request sent to HR. You will be told when it is ready.');
    }

    private function canAnswer(): bool
    {
        return $this->user()->can('payroll.manage');
    }

    public function sendLegacy(int $id, \App\Services\PayslipRequestService $service): void
    {
        abort_unless($this->canAnswer(), 403);
        $service->sendFromLegacy(\App\Models\PayslipRequest::with('employee')->findOrFail($id), $this->user());
        $this->toast('Payslip sent from Legacy Salary.');
    }

    public function openUpload(int $id): void
    {
        abort_unless($this->canAnswer(), 403);
        $this->fill(['uploadingId' => $id, 'uploadFile' => null]);
        $this->resetValidation();
    }

    public function saveUpload(\App\Services\PayslipRequestService $service): void
    {
        abort_unless($this->canAnswer(), 403);
        $this->validate(['uploadFile' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']], ['uploadFile.mimes' => 'Upload a PDF or a picture (JPG / PNG).'], ['uploadFile' => 'file']);
        $service->sendFile(\App\Models\PayslipRequest::with('employee')->findOrFail($this->uploadingId), $this->uploadFile, $this->user());
        $this->fill(['uploadingId' => null, 'uploadFile' => null]);
        $this->toast('Payslip file sent.');
    }

    public function openReject(int $id): void
    {
        abort_unless($this->canAnswer(), 403);
        $this->fill(['rejectingId' => $id, 'rejectNote' => '']);
        $this->resetValidation();
    }

    public function saveReject(\App\Services\PayslipRequestService $service): void
    {
        abort_unless($this->canAnswer(), 403);
        $this->validate(['rejectNote' => ['required', 'string', 'min:3', 'max:1000']], ['rejectNote.required' => 'Say why it cannot be given.'], ['rejectNote' => 'reason']);
        $service->reject(\App\Models\PayslipRequest::with('employee')->findOrFail($this->rejectingId), $this->rejectNote, $this->user());
        $this->rejectingId = null;
        $this->toast('Request answered.');
    }

    public function closeModal(): void
    {
        $this->rejectingId = null;
        $this->uploadingId = null;
    }

    public function render(\App\Services\PayslipRequestService $service)
    {
        $canAll = $this->user()->canAny(['payroll.manage', 'payroll.approve']);
        $payslips = Payslip::with(['employee', 'item.run', 'item.payments'])
            ->when($this->scope === 'mine' || ! $canAll, fn ($q) => $q->where('employee_id', $this->ownEmployee()?->id ?? 0))
            ->when($this->scope === 'all' && trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.trim($this->search).'%')->orWhereHas('employee', fn ($e) => $e->where('name', 'like', '%'.trim($this->search).'%')->orWhere('employee_code', 'like', '%'.trim($this->search).'%'))))
            ->latest('issued_at')->paginate(15);

        $own = $this->ownEmployee();
        // Requests: the person's own, and (payroll staff) the ones still waiting, with whether Legacy Salary has that month.
        $pending = $this->canAnswer() ? \App\Models\PayslipRequest::with('employee')->where('status', 'pending')->oldest()->get()->each(fn ($r) => $r->setAttribute('has_legacy', $r->employee && $service->legacyFor($r->employee, $r->month) !== null)) : collect();

        return $this->page('livewire.payroll.payslips', ['payslips' => $payslips, 'canAll' => $canAll, 'own' => $own,
            'myRequests' => $own ? \App\Models\PayslipRequest::with('decider')->where('employee_id', $own->id)->latest('id')->limit(12)->get() : collect(),
            'pendingRequests' => $pending, 'canAnswer' => $this->canAnswer()], 'Payslips');
    }
}
