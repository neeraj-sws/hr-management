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
    use InteractsWithHr, WithFileUploads, \Livewire\WithPagination;

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

    private function documentRules(): array
    {
        return [
            'employeeId' => ['required', 'exists:attendance_employees,id'],
            'documentCategory' => ['required', 'in:'.implode(',', array_keys(self::CATEGORIES))],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
            'expiresOn' => ['nullable', 'date', 'after:2000-01-01'],
        ];
    }

    private function documentMessages(): array
    {
        return [
            'employeeId.required' => 'Choose the employee this document belongs to.',
            'file.required' => 'Choose a file to upload.',
            'file.uploaded' => 'The file could not be uploaded. It may be larger than the server allows (max 10 MB). Choose a smaller file.',
            'file.max' => 'The file is larger than 10 MB.',
            'file.mimes' => 'Upload a PDF, JPG, PNG or Word (.doc/.docx) file.',
        ];
    }

    /** Checks a field as soon as it changes, so problems show before pressing Upload. */
    public function updated(string $property): void
    {
        if (in_array($property, ['employee', 'category'], true)) $this->resetPage();
        if (in_array($property, ['file', 'employeeId', 'documentCategory', 'expiresOn'], true) && $this->showForm) {
            $this->validateOnly($property, $this->documentRules(), $this->documentMessages(), ['employeeId' => 'employee', 'documentCategory' => 'category', 'expiresOn' => 'expiry date']);
        }
    }

    public function upload(AuditLogger $audit): void
    {
        abort_unless($this->canManage(), 403);
        // On a validation error the form stays open with everything already entered (including the chosen file).
        $this->validate($this->documentRules(), $this->documentMessages(), ['employeeId' => 'employee', 'documentCategory' => 'category', 'expiresOn' => 'expiry date']);
        $path = $this->file->store('employee-documents/'.$this->employeeId, 'local');
        if (! $path) { $this->addError('file', 'The file could not be saved on the server. Please try again.'); return; }
        try {
            $document = EmployeeDocument::create(['employee_id' => $this->employeeId, 'uploaded_by' => auth()->id(), 'category' => $this->documentCategory, 'original_name' => $this->file->getClientOriginalName(), 'storage_path' => $path, 'expires_on' => $this->expiresOn ?: null]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        $audit->record('employee.document_uploaded', $document, ['category' => $document->category, 'name' => $document->original_name]);
        $employee = AttendanceEmployee::with('user')->find($this->employeeId);
        \App\Services\Notifier::send($employee?->user, 'EmployeeDocument', $document->id, 'A document was added to your profile: '.(self::CATEGORIES[$document->category] ?? $document->category).' ('.$document->original_name.').', route('lifecycle.documents', [], false), auth()->id());
        $this->showForm = false;
        $this->reset('file', 'employeeId', 'expiresOn');
        $this->resetValidation();
        $this->dispatch('document-uploaded');
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
        $this->reset('file');
        $this->resetValidation();
    }

    public function render()
    {
        $documents = EmployeeDocument::with(['employee', 'uploader'])
            ->when(! $this->canManage(), fn ($q) => $q->where('employee_id', $this->ownEmployee()?->id ?? 0))
            ->when($this->canManage() && $this->employee, fn ($q) => $q->where('employee_id', $this->employee))
            ->when($this->category, fn ($q, $c) => $q->where('category', $c))
            ->latest()->paginate(15);

        return $this->page('livewire.lifecycle.documents', [
            'documents' => $documents,
            'canManage' => $this->canManage(),
            'categories' => self::CATEGORIES,
            'employees' => $this->canManage() ? AttendanceEmployee::orderBy('name')->get(['id', 'name']) : collect(),
            'expiring' => EmployeeDocument::when(! $this->canManage(), fn ($q) => $q->where('employee_id', $this->ownEmployee()?->id ?? 0))->whereNotNull('expires_on')->whereDate('expires_on', '<=', today()->addDays(30))->count(),
        ], 'Documents');
    }
}
