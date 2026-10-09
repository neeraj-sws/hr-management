<?php

namespace App\Livewire\Lifecycle;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\EmployeeAsset;
use App\Services\AuditLogger;
use Livewire\Attributes\Url;
use Livewire\Component;

class Assets extends Component
{
    use InteractsWithHr;

    #[Url] public string $status = 'assigned';
    #[Url] public string $search = '';
    public bool $showForm = false;
    public ?int $employeeId = null;
    public string $assetTag = '';
    public string $name = '';
    public string $conditionNotes = '';
    public ?int $returningId = null;
    public string $returnNotes = '';
    public ?int $lostId = null;
    public string $lostAction = 'lost';
    public string $lostNote = '';
    public string $recoveryAmount = '';

    private function canManage(): bool
    {
        return $this->user()->can('lifecycle.manage');
    }

    public function create(): void
    {
        abort_unless($this->canManage(), 403);
        $this->reset('employeeId', 'assetTag', 'name', 'conditionNotes');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function assign(AuditLogger $audit): void
    {
        abort_unless($this->canManage(), 403);
        $this->validate(['employeeId' => ['required', 'exists:attendance_employees,id'], 'assetTag' => ['required', 'string', 'max:100', 'unique:employee_assets,asset_tag'], 'name' => ['required', 'string', 'max:255'], 'conditionNotes' => ['nullable', 'string', 'max:1000']], [], ['employeeId' => 'employee', 'assetTag' => 'asset tag']);
        $asset = EmployeeAsset::create(['employee_id' => $this->employeeId, 'asset_tag' => $this->assetTag, 'name' => $this->name, 'status' => 'assigned', 'assigned_on' => today(), 'condition_notes' => $this->conditionNotes ?: null]);
        $audit->record('employee.asset_assigned', $asset);
        $this->showForm = false;
        $this->toast('Asset assigned.');
    }

    public function openReturn(int $id): void
    {
        abort_unless($this->canManage(), 403);
        $this->returningId = $id;
        $this->returnNotes = '';
    }

    public function markReturned(AuditLogger $audit): void
    {
        abort_unless($this->canManage(), 403);
        $asset = EmployeeAsset::where('status', 'assigned')->findOrFail($this->returningId);
        $asset->update(['status' => 'returned', 'returned_on' => today(), 'condition_notes' => trim(($asset->condition_notes ? $asset->condition_notes."\n" : '').($this->returnNotes ? 'Returned: '.$this->returnNotes : '')) ?: null]);
        $audit->record('employee.asset_returned', $asset);
        $this->returningId = null;
        $this->toast('Asset returned.');
    }

    /** lost → (written_off | recovery requested through the normal salary-adjustment approval) — never a direct payroll deduction. */
    public function openLost(int $id, string $action = 'lost'): void
    {
        abort_unless($this->canManage(), 403);
        $this->fill(['lostId' => $id, 'lostAction' => in_array($action, ['lost', 'write_off', 'recovery'], true) ? $action : 'lost', 'lostNote' => '', 'recoveryAmount' => '']);
        $this->resetValidation();
    }

    public function saveLost(AuditLogger $audit, \App\Services\PeopleOperationsService $people): void
    {
        abort_unless($this->canManage(), 403);
        $this->validate(['lostNote' => ['required', 'string', 'min:3', 'max:1000'], 'recoveryAmount' => [$this->lostAction === 'recovery' ? 'required' : 'nullable', 'numeric', 'gt:0']], ['lostNote.required' => 'Add a note.'], ['recoveryAmount' => 'amount to recover']);
        $asset = EmployeeAsset::with('employee')->findOrFail($this->lostId);
        $note = trim(($asset->resolution_note ? $asset->resolution_note."\n" : '').now()->format('d M Y').': '.$this->lostNote);
        if ($this->lostAction === 'lost') {
            abort_unless($asset->status === 'assigned', 409);
            $asset->update(['status' => 'lost', 'resolution_note' => $note]);
            $audit->record('employee.asset_lost', $asset);
            $this->toast('Marked as lost. Offboarding stays blocked until it is written off or a recovery is requested.');
        } elseif ($this->lostAction === 'write_off') {
            abort_unless($asset->status === 'lost', 409);
            $asset->update(['status' => 'written_off', 'resolution_note' => $note]);
            $audit->record('employee.asset_written_off', $asset);
            $this->toast('Asset written off.');
        } else {
            abort_unless($asset->status === 'lost' && $asset->employee, 409);
            $adjustment = $people->requestAdjustment($asset->employee, (float) $this->recoveryAmount, 'deduction', today()->toDateString(), "Recovery for lost asset {$asset->asset_tag} ({$asset->name}): {$this->lostNote}", auth()->id());
            $asset->update(['status' => 'recovery_requested', 'resolution_note' => $note, 'recovery_adjustment_id' => $adjustment->id]);
            $audit->record('employee.asset_recovery_requested', $asset, ['amount' => (float) $this->recoveryAmount, 'adjustment_id' => $adjustment->id]);
            $this->toast('Recovery sent for approval as a salary deduction.');
        }
        $this->lostId = null;
    }

    public function closeModal(): void
    {
        $this->showForm = false;
        $this->returningId = null;
        $this->lostId = null;
    }

    public function render()
    {
        $term = trim($this->search);
        $assets = EmployeeAsset::with('employee')
            ->when(! $this->canManage(), fn ($q) => $q->where('employee_id', $this->ownEmployee()?->id ?? 0))
            ->when($this->status, fn ($q, $s) => $q->where('status', $s))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('asset_tag', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%")->orWhereHas('employee', fn ($e) => $e->where('name', 'like', "%{$term}%"))))
            ->latest()->get();

        return $this->page('livewire.lifecycle.assets', [
            'assets' => $assets,
            'canManage' => $this->canManage(),
            'employees' => $this->canManage() ? AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
        ], 'Assets');
    }
}
