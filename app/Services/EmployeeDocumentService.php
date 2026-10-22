<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Employee documents: HR uploads any type; employees upload their own onboarding papers
 * (resume, ID proofs, bank details …). HR picks which are required at onboarding, and each
 * employee sees a checklist of what is still missing. Files stay on the private disk.
 */
class EmployeeDocumentService
{
    /** Every document type. */
    public const CATEGORIES = [
        'resume' => 'Resume / CV',
        'aadhaar' => 'Aadhaar card',
        'pan' => 'PAN card',
        'identity' => 'Identity proof (passport, voter ID, driving licence)',
        'address' => 'Address proof',
        'education' => 'Education certificates',
        'experience' => 'Experience letter (previous employer)',
        'salary_slips' => 'Salary slips (previous employer)',
        'bank' => 'Bank details (cancelled cheque / passbook)',
        'employment' => 'Employment contract',
        'appointment' => 'Appointment letter',
        'relieving' => 'Relieving letter',
        'noc' => 'NOC (No Objection Certificate)',
        'policy' => 'Signed policy',
        'other' => 'Other',
    ];

    /** Types the company issues: only HR uploads these. */
    public const HR_ONLY = ['employment', 'appointment', 'relieving', 'noc'];

    /** "photo" is the profile photo; it can be a required onboarding item too. */
    public const PHOTO = 'photo';

    /** Required at onboarding until HR changes the list. */
    public const DEFAULT_REQUIRED = ['photo', 'resume', 'aadhaar', 'pan', 'address', 'education', 'bank'];

    public const FILE_RULES = ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'];

    public function __construct(private readonly AuditLogger $audit, private readonly EmployeePhotoService $photos) {}

    /** Types an employee may upload for themself. */
    public static function employeeCategories(): array
    {
        return array_diff_key(self::CATEGORIES, array_flip(self::HR_ONLY));
    }

    /** Checklist items HR can require: the photo plus every type an employee can upload. */
    public static function requirable(): array
    {
        return [self::PHOTO => 'Photo'] + self::employeeCategories();
    }

    public function canManage(User $user): bool
    {
        return $user->can('lifecycle.manage');
    }

    /** @return list<string> */
    public function required(): array
    {
        $config = app(PolicyConfigurationService::class)->forEmployee(null, 'documents.onboarding', today()->toDateString(), ['required' => self::DEFAULT_REQUIRED]);

        return array_values(array_intersect(array_keys(self::requirable()), (array) ($config['required'] ?? self::DEFAULT_REQUIRED)));
    }

    public function setRequired(array $keys, User $actor): void
    {
        abort_unless($this->canManage($actor), 403);
        $keys = array_values(array_intersect(array_keys(self::requirable()), $keys));
        app(PeopleOperationsService::class)->setPolicy('documents.onboarding', 'documents', 'Onboarding documents', ['required' => $keys], null, $actor->id, today()->toDateString());
    }

    /**
     * What this employee still has to hand in.
     *
     * @return list<array{key: string, label: string, done: bool, count: int}>
     */
    public function checklist(AttendanceEmployee $employee): array
    {
        $counts = EmployeeDocument::where('employee_id', $employee->id)->selectRaw('category, COUNT(*) as n')->groupBy('category')->pluck('n', 'category');
        $labels = self::requirable();

        return array_map(function (string $key) use ($employee, $counts, $labels) {
            $count = $key === self::PHOTO ? ($employee->photo_path ? 1 : 0) : (int) ($counts[$key] ?? 0);

            return ['key' => $key, 'label' => $labels[$key], 'done' => $count > 0, 'count' => $count];
        }, $this->required());
    }

    /** Stores a file for the employee (the photo goes to the profile photo). Employees only for themselves. */
    public function store(AttendanceEmployee $employee, string $category, UploadedFile $file, User $actor, ?string $expiresOn = null): ?EmployeeDocument
    {
        $manager = $this->canManage($actor);
        abort_unless($manager || $employee->user_id === $actor->id, 403);
        if ($category === self::PHOTO) {
            $this->photos->save($employee, $file, $actor);
            $this->tellOtherSide($employee, $actor, 'Photo', null);

            return null;
        }
        $allowed = $manager ? self::CATEGORIES : self::employeeCategories();
        if (! isset($allowed[$category])) throw ValidationException::withMessages(['category' => 'Choose a document type you can upload.']);
        $path = $file->store('employee-documents/'.$employee->id, 'local');
        if (! $path) throw ValidationException::withMessages(['file' => 'The file could not be saved on the server. Please try again.']);
        try {
            $document = EmployeeDocument::create(['employee_id' => $employee->id, 'uploaded_by' => $actor->id, 'category' => $category, 'original_name' => $file->getClientOriginalName(), 'storage_path' => $path, 'expires_on' => $expiresOn ?: null]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        $this->audit->record('employee.document_uploaded', $document, ['category' => $category, 'name' => $document->original_name, 'by_employee' => ! $manager]);
        $this->tellOtherSide($employee, $actor, self::CATEGORIES[$category], $document);

        return $document;
    }

    /** HR deletes any document; an employee only one they uploaded themself. */
    public function delete(EmployeeDocument $document, User $actor): void
    {
        abort_unless($this->canManage($actor) || ($document->uploaded_by === $actor->id && $document->employee?->user_id === $actor->id), 403);
        $this->audit->record('employee.document_deleted', $document, ['category' => $document->category, 'name' => $document->original_name]);
        Storage::disk('local')->delete($document->storage_path);
        $document->delete();
    }

    /** An employee's upload tells HR; HR's upload tells the employee. */
    private function tellOtherSide(AttendanceEmployee $employee, User $actor, string $label, ?EmployeeDocument $document): void
    {
        $url = route('lifecycle.documents', ['employee' => $employee->id], false);
        if ($employee->user_id === $actor->id) {
            $missing = collect($this->checklist($employee->fresh()))->where('done', false)->count();
            Notifier::send(Notifier::withPermission('lifecycle.manage'), 'EmployeeDocument', $document?->id ?? $employee->id, "{$employee->name} uploaded: {$label}".($document ? " ({$document->original_name})" : '').'.'.($missing ? " {$missing} onboarding document(s) still missing." : ' All onboarding documents are in.'), $url, $actor->id);
        } elseif ($document) {
            Notifier::send($employee->user, 'EmployeeDocument', $document->id, "A document was added to your profile: {$label} ({$document->original_name}).", route('lifecycle.documents', [], false), $actor->id);
        }
    }
}
