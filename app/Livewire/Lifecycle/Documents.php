<?php

namespace App\Livewire\Lifecycle;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\EmployeeDocument;
use App\Services\EmployeeDocumentService;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

class Documents extends Component
{
    use InteractsWithHr, WithFileUploads;

    /** Kept here for the views and API that read it. */
    public const CATEGORIES = EmployeeDocumentService::CATEGORIES;

    #[Url] public ?int $employee = null;
    #[Url] public string $category = '';
    public bool $showForm = false;
    public ?int $employeeId = null;
    public string $documentCategory = 'employment';
    public $file = null;
    public string $expiresOn = '';
    /** HR: which documents are required at onboarding (checkbox list). */
    public array $required = [];
    public bool $editRequired = false;

    /** ?upload=1 (Quick actions in the search): open the upload form straight away. */
    public function mount(): void
    {
        if (request()->boolean('upload') && ($this->canManage() || $this->ownEmployee())) $this->create();
    }

    private function canManage(): bool
    {
        return $this->user()->can('lifecycle.manage');
    }

    /** HR uploads for anyone; an employee uploads their own papers (optionally straight for one checklist item). */
    public function create(?string $for = null): void
    {
        $own = $this->ownEmployee();
        abort_unless($this->canManage() || $own, 403);
        $this->reset('employeeId', 'file', 'expiresOn');
        $categories = $this->uploadCategories();
        $this->documentCategory = $for && isset($categories[$for]) ? $for : ($this->canManage() ? 'employment' : 'resume');
        $this->employeeId = $this->canManage() ? $this->employee : $own->id; // the employee picked in the filter is preselected
        $this->resetValidation();
        $this->showForm = true;
    }

    /** Types the signed-in person can upload (the photo included). */
    private function uploadCategories(): array
    {
        return [EmployeeDocumentService::PHOTO => 'Photo'] + ($this->canManage() ? EmployeeDocumentService::CATEGORIES : EmployeeDocumentService::employeeCategories());
    }

    public function editRequiredList(EmployeeDocumentService $service): void
    {
        abort_unless($this->canManage(), 403);
        $this->required = $service->required();
        $this->editRequired = true;
    }

    public function saveRequired(EmployeeDocumentService $service): void
    {
        abort_unless($this->canManage(), 403);
        $service->setRequired($this->required, $this->user());
        $this->editRequired = false;
        $this->toast('Onboarding documents saved: employees now see this checklist.');
    }

    private function documentRules(): array
    {
        return [
            'employeeId' => ['required', 'exists:attendance_employees,id'],
            'documentCategory' => ['required', 'in:'.implode(',', array_keys($this->uploadCategories()))],
            'file' => $this->documentCategory === EmployeeDocumentService::PHOTO ? \App\Services\EmployeePhotoService::RULES : EmployeeDocumentService::FILE_RULES,
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
            'file.mimes' => $this->documentCategory === EmployeeDocumentService::PHOTO ? 'Upload the photo as JPG, PNG or WebP.' : 'Upload a PDF, JPG, PNG or Word (.doc/.docx) file.',
        ];
    }

    /** Checks a field as soon as it changes, so problems show before pressing Upload. */
    public function updated(string $property): void
    {
        if (in_array($property, ['file', 'employeeId', 'documentCategory', 'expiresOn'], true) && $this->showForm) {
            $this->validateOnly($property, $this->documentRules(), $this->documentMessages(), ['employeeId' => 'employee', 'documentCategory' => 'category', 'expiresOn' => 'expiry date']);
        }
    }

    /** Not named "upload": that is Livewire's own JS file-upload helper, so wire:submit="upload" never reached the server. */
    public function saveDocument(EmployeeDocumentService $service): void
    {
        if (! $this->canManage()) $this->employeeId = $this->ownEmployee()?->id; // employees only ever upload for themselves
        // On a validation error the form stays open with everything already entered (including the chosen file).
        $this->validate($this->documentRules(), $this->documentMessages(), ['employeeId' => 'employee', 'documentCategory' => 'category', 'expiresOn' => 'expiry date']);
        $service->store(AttendanceEmployee::findOrFail($this->employeeId), $this->documentCategory, $this->file, $this->user(), $this->expiresOn ?: null);
        $photo = $this->documentCategory === EmployeeDocumentService::PHOTO;
        $this->showForm = false;
        $this->reset('file', 'employeeId', 'expiresOn');
        $this->resetValidation();
        $this->dispatch('document-uploaded');
        $this->toast($photo ? 'Photo saved.' : ($this->canManage() ? 'Document stored privately.' : 'Uploaded — HR has been told.'));
    }

    public function delete(int $id, EmployeeDocumentService $service): void
    {
        $service->delete(EmployeeDocument::findOrFail($id), $this->user());
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
        $own = $this->ownEmployee()?->fresh(); // fresh: a photo saved a moment ago shows at once
        $documents = EmployeeDocument::with(['employee', 'uploader'])
            ->when(! $this->canManage(), fn ($q) => $q->where('employee_id', $own?->id ?? 0))
            ->when($this->canManage() && $this->employee, fn ($q) => $q->where('employee_id', $this->employee))
            ->latest()->limit(300)->get();
        $checklistFor = $this->canManage() ? ($this->employee ? AttendanceEmployee::find($this->employee) : null) : $own;
        $disk = Storage::disk('local');
        $size = function (?string $path) use ($disk) {
            try { return $path && $disk->exists($path) ? $disk->size($path) : null; } catch (\Throwable) { return null; }
        };

        // "My files": every stored document, plus the profile photo (it is kept on the profile, not as a document).
        $files = $documents->map(fn (EmployeeDocument $d) => [
            'id' => $d->id, 'name' => $d->original_name, 'category' => self::CATEGORIES[$d->category] ?? ucfirst($d->category),
            'group' => EmployeeDocumentService::group($d->category), 'kind' => self::fileKind($d->original_name), 'size' => $size($d->storage_path),
            'date' => $d->created_at, 'by' => $d->uploader?->name, 'employee' => $d->employee?->name, 'expires' => $d->expires_on,
            'view' => route('hr.documents.view', $d), 'download' => route('hr.documents.download', $d),
            'canDelete' => $this->canManage() || $d->uploaded_by === auth()->id(),
        ]);
        if ($checklistFor?->photo_path && $disk->exists($checklistFor->photo_path)) {
            $files->prepend(['id' => null, 'name' => 'Profile photo', 'category' => 'Photo', 'group' => 'other', 'kind' => 'image',
                'size' => $size($checklistFor->photo_path), 'date' => \Illuminate\Support\Carbon::createFromTimestamp($disk->lastModified($checklistFor->photo_path), config('app.timezone')),
                'by' => null, 'employee' => $checklistFor->name, 'expires' => null,
                'view' => route('people.photo', $checklistFor), 'download' => route('people.photo', $checklistFor), 'canDelete' => false]);
        }
        $groupCounts = ['all' => $files->count()] + collect(['identity', 'education', 'bank', 'other'])->mapWithKeys(fn ($g) => [$g => $files->where('group', $g)->count()])->all();

        return $this->page('livewire.lifecycle.documents', [
            'files' => $files->values(),
            'groupCounts' => $groupCounts,
            'canManage' => $this->canManage(),
            'categories' => self::CATEGORIES,
            'uploadCategories' => $this->uploadCategories(),
            'requirable' => EmployeeDocumentService::requirable(),
            'requiredKeys' => app(EmployeeDocumentService::class)->required(),
            // The checklist: your own (employee), or the employee picked in the filter (HR).
            'checklistFor' => $checklistFor,
            'checklist' => $checklistFor ? app(EmployeeDocumentService::class)->checklist($checklistFor) : [],
            'canUpload' => $this->canManage() || $own !== null,
            'employees' => $this->canManage() ? AttendanceEmployee::orderBy('name')->get(['id', 'name']) : collect(),
            'expiring' => EmployeeDocument::when(! $this->canManage(), fn ($q) => $q->where('employee_id', $own?->id ?? 0))->whereNotNull('expires_on')->whereDate('expires_on', '<=', today()->addDays(30))->count(),
        ], 'Documents');
    }

    /** pdf, image or other: picks the icon colour on the file row. */
    public static function fileKind(?string $name): string
    {
        $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));

        return $ext === 'pdf' ? 'pdf' : (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) ? 'image' : 'other');
    }
}
