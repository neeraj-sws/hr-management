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
    use InteractsWithHr, \Livewire\WithPagination;

    /** '' = every asset: returned ones stay listed (with their history) instead of disappearing. */
    #[Url] public string $status = '';
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
    public ?int $historyId = null;
    public ?int $reissueId = null;
    public ?int $reissueEmployeeId = null;
    public string $reissueNotes = '';

    public function updated($property): void
    {
        if (in_array($property, ['status', 'search'], true)) $this->resetPage();
    }

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
        $asset->recordEvent('assigned', $asset->employee_id, $this->conditionNotes);
        $audit->record('employee.asset_assigned', $asset, ['employee_id' => $asset->employee_id, 'asset_tag' => $asset->asset_tag]);
        $this->notifyHolder($asset, "{$asset->name} ({$asset->asset_tag}) was issued to you.");
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
        // The asset keeps who had it, so it stays in both the employee's and the admin's lists as "returned".
        $asset->update(['status' => 'returned', 'returned_on' => today(), 'condition_notes' => trim(($asset->condition_notes ? $asset->condition_notes."\n" : '').($this->returnNotes ? 'Returned: '.$this->returnNotes : '')) ?: null]);
        $asset->recordEvent('returned', $asset->employee_id, $this->returnNotes);
        $audit->record('employee.asset_returned', $asset, ['employee_id' => $asset->employee_id, 'asset_tag' => $asset->asset_tag]);
        $this->notifyHolder($asset, "Return of {$asset->name} ({$asset->asset_tag}) was recorded on ".today()->format('d M Y').'.');
        $this->returningId = null;
        $this->toast('Asset marked as returned. It stays in the list with its history.');
    }

    public function openHistory(int $id): void
    {
        $this->historyId = $this->visibleAssets()->findOrFail($id)->id;
    }

    /** Issues a returned asset to someone (or the same person) again; earlier holders stay in its history. */
    public function openReissue(int $id): void
    {
        abort_unless($this->canManage(), 403);
        $this->fill(['reissueId' => EmployeeAsset::where('status', 'returned')->findOrFail($id)->id, 'reissueEmployeeId' => null, 'reissueNotes' => '']);
        $this->resetValidation();
    }

    public function reissue(AuditLogger $audit): void
    {
        abort_unless($this->canManage(), 403);
        $this->validate(['reissueEmployeeId' => ['required', 'exists:attendance_employees,id'], 'reissueNotes' => ['nullable', 'string', 'max:1000']], [], ['reissueEmployeeId' => 'employee']);
        $asset = EmployeeAsset::where('status', 'returned')->findOrFail($this->reissueId);
        $previous = $asset->employee_id;
        $asset->update(['employee_id' => $this->reissueEmployeeId, 'status' => 'assigned', 'assigned_on' => today(), 'returned_on' => null, 'condition_notes' => $this->reissueNotes ?: $asset->condition_notes]);
        $asset->recordEvent('assigned', $asset->employee_id, $this->reissueNotes);
        $audit->record('employee.asset_assigned', $asset, ['employee_id' => $asset->employee_id, 'previous_employee_id' => $previous, 'asset_tag' => $asset->asset_tag, 'reissued' => true]);
        $this->notifyHolder($asset->fresh(), "{$asset->name} ({$asset->asset_tag}) was issued to you.");
        $this->reissueId = null;
        $this->toast('Asset issued again. Its earlier assignments are kept in the history.');
    }

    private function notifyHolder(EmployeeAsset $asset, string $message): void
    {
        \App\Services\Notifier::send($asset->employee()->with('user')->first()?->user, 'EmployeeAsset', $asset->id, $message, route('lifecycle.assets', [], false), auth()->id());
    }

    /** Admins see all assets; an employee sees assets they hold or held before. */
    private function visibleAssets()
    {
        if ($this->canManage()) return EmployeeAsset::query();
        $own = $this->ownEmployee()?->id ?? 0;

        return EmployeeAsset::query()->where(fn ($q) => $q->where('employee_id', $own)->orWhereHas('events', fn ($e) => $e->where('employee_id', $own)));
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
            $asset->recordEvent('lost', $asset->employee_id, $this->lostNote);
            $audit->record('employee.asset_lost', $asset);
            $this->toast('Marked as lost. Offboarding stays blocked until it is written off or a recovery is requested.');
        } elseif ($this->lostAction === 'write_off') {
            abort_unless($asset->status === 'lost', 409);
            $asset->update(['status' => 'written_off', 'resolution_note' => $note]);
            $asset->recordEvent('written_off', $asset->employee_id, $this->lostNote);
            $audit->record('employee.asset_written_off', $asset);
            $this->toast('Asset written off.');
        } else {
            abort_unless($asset->status === 'lost' && $asset->employee, 409);
            $adjustment = $people->requestAdjustment($asset->employee, (float) $this->recoveryAmount, 'deduction', today()->toDateString(), "Recovery for lost asset {$asset->asset_tag} ({$asset->name}): {$this->lostNote}", auth()->id());
            $asset->update(['status' => 'recovery_requested', 'resolution_note' => $note, 'recovery_adjustment_id' => $adjustment->id]);
            $asset->recordEvent('recovery_requested', $asset->employee_id, $this->lostNote.' (recover '.number_format((float) $this->recoveryAmount, 2).')');
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
        $this->historyId = null;
        $this->reissueId = null;
    }

    public function render()
    {
        $term = trim($this->search);
        $own = $this->ownEmployee();
        $assets = $this->visibleAssets()->with('employee')->withCount('events')
            ->when($this->status, fn ($q, $s) => $q->where('status', $s))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('asset_tag', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%")->orWhereHas('employee', fn ($e) => $e->where('name', 'like', "%{$term}%"))))
            ->orderByRaw("CASE status WHEN 'assigned' THEN 0 WHEN 'lost' THEN 1 ELSE 2 END")->latest('updated_at')->paginate(15);

        return $this->page('livewire.lifecycle.assets', [
            'assets' => $assets,
            'own' => $own,
            'history' => $this->historyId ? $this->visibleAssets()->with(['events.employee', 'events.recorder'])->find($this->historyId) : null,
            'reissuing' => $this->reissueId ? EmployeeAsset::find($this->reissueId) : null,
            'canManage' => $this->canManage(),
            'employees' => $this->canManage() ? AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name']) : collect(),
        ], 'Assets');
    }
}
