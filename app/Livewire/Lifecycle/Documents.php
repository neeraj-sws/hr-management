<?php

namespace App\Livewire\Lifecycle;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\EmployeeDocument;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

class Documents extends Component
{
    use InteractsWithHr, WithFileUploads;

    public const CATEGORIES = ['employment' => 'Employment contract', 'identity' => 'Identity proof', 'education' => 'Education', 'experience' => 'Experience letter', 'policy' => 'Signed policy', 'other' => 'Other'];

    #[Url] public ?int $employee = null;
    #[Url] public string $category = '';
    public bool $showForm = false;
    public ?int $employeeId = null;
    public string $documentCategory = 'employment';
    public $file = null;
    public string $expiresOn = '';

    private function canManage(): bool
    {
        return $this->user()->can('lifecycle.manage');
    }

    public function create(): void
    {
        abort_unless($this->canManage(), 403);
        $this->reset('employeeId', 'file', 'expiresOn');
        $this->documentCategory = 'employment';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function upload(AuditLogger $audit): void
    {
        abort_unless($this->canManage(), 403);
        $this->validate([
            'employeeId' => ['required', 'exists:attendance_employees,id'],
            'documentCategory' => ['required', 'in:'.implode(',', array_keys(self::CATEGORIES))],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
            'expiresOn' => ['nullable', 'date'],
        ], [], ['employeeId' => 'employee', 'documentCategory' => 'category']);
        $path = $this->file->store('employee-documents/'.$this->employeeId, 'local');
        $document = EmployeeDocument::create(['employee_id' => $this->employeeId, 'uploaded_by' => auth()->id(), 'category' => $this->documentCategory, 'original_name' => $this->file->getClientOriginalName(), 'storage_path' => $path, 'expires_on' => $this->expiresOn ?: null]);
        $audit->record('employee.document_uploaded', $document, ['category' => $document->category]);
        $this->showForm = false;
        $this->reset('file');
        $this->toast('Document stored privately.');
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        abort_unless($this->canManage(), 403);
        $document = EmployeeDocument::findOrFail($id);
        $audit->record('employee.document_deleted', $document, ['category' => $document->category, 'name' => $document->original_name]);
        Storage::disk('local')->delete($document->storage_path);
        $document->delete();
        $this->toast('Document deleted.');
    }

    public function closeModal(): void
    {
        $this->showForm = false;
    }

    public function render()
    {
        $documents = EmployeeDocument::with(['employee', 'uploader'])
            ->when(! $this->canManage(), fn ($q) => $q->where('employee_id', $this->ownEmployee()?->id ?? 0))
            ->when($this->canManage() && $this->employee, fn ($q) => $q->where('employee_id', $this->employee))
            ->when($this->category, fn ($q, $c) => $q->where('category', $c))
            ->latest()->get();

        return $this->page('livewire.lifecycle.documents', [
            'documents' => $documents,
            'canManage' => $this->canManage(),
            'categories' => self::CATEGORIES,
            'employees' => $this->canManage() ? AttendanceEmployee::orderBy('name')->get(['id', 'name']) : collect(),
            'expiring' => $documents->filter(fn ($d) => $d->expires_on && $d->expires_on->lte(today()->addDays(30)))->count(),
        ], 'Documents');
    }
}
