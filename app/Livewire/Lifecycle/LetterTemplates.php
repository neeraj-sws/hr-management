<?php

namespace App\Livewire\Lifecycle;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\LetterTemplate;
use App\Services\HrLetterService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Employee Lifecycle > Letter templates (HR / admin): the wording of the appointment, offer, NOC, relieving and
 * experience letters (and any others HR adds), with {placeholders} filled from the employee's profile.
 */
class LetterTemplates extends Component
{
    use InteractsWithHr, \Livewire\WithFileUploads;

    /** Signature and company seal images printed on every letter / certificate. */
    public $signatureUpload = null;
    public $sealUpload = null;
    /** A Word (.docx) file whose text becomes the template being edited. */
    public $wordFile = null;

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $templateName = '';
    public string $templateCategory = 'hr_letter';
    public string $templateBody = '';
    /** Employee whose real data fills the preview. */
    public ?int $previewEmployeeId = null;

    public string $signatoryName = '';
    public string $signatoryDesignation = '';
    /** Letterhead: company name, address and contacts. */
    public array $company = [];

    public function mount(HrLetterService $letters): void
    {
        abort_unless(HrLetterService::canManage($this->user()), 403);
        ['signatory_name' => $this->signatoryName, 'signatory_designation' => $this->signatoryDesignation] = $letters->settings();
        $this->company = $letters->companyFields();
    }

    public function create(): void
    {
        $this->resetErrorBag();
        $this->fill(['showForm' => true, 'editingId' => null, 'templateName' => '', 'templateCategory' => 'hr_letter', 'templateBody' => '<p>Date: {today}</p><p>Dear {first_name},</p><p></p><p>Yours sincerely,</p><p>{signature}</p>']);
    }

    public function edit(int $id): void
    {
        $template = LetterTemplate::findOrFail($id);
        $this->resetErrorBag();
        $this->fill(['showForm' => true, 'editingId' => $template->id, 'templateName' => $template->name, 'templateCategory' => $template->category, 'templateBody' => $template->body]);
    }

    /** A copy to start a new letter from an existing one. */
    public function duplicate(int $id): void
    {
        $template = LetterTemplate::findOrFail($id);
        $this->resetErrorBag();
        $this->fill(['showForm' => true, 'editingId' => null, 'templateName' => $template->name.' (copy)', 'templateCategory' => $template->category, 'templateBody' => $template->body]);
    }

    public function closeModal(): void
    {
        $this->showForm = false;
    }

    public function save(HrLetterService $letters): void
    {
        $this->resetErrorBag();
        $template = $this->editingId ? LetterTemplate::findOrFail($this->editingId) : null;
        $letters->saveTemplate($template, ['name' => $this->templateName, 'category' => $this->templateCategory, 'body' => $this->templateBody], $this->user());
        $this->showForm = false;
        $this->toast($template ? 'Template saved.' : 'Template added. It can now be used from an employee\'s profile > Docs & assets > Generate letter.');
    }

    public function toggle(int $id, HrLetterService $letters): void
    {
        $template = $letters->toggleTemplate(LetterTemplate::findOrFail($id), $this->user());
        $this->toast($template->is_active ? 'Template turned on.' : 'Template turned off. Letters already issued stay in the documents.');
    }

    public function delete(int $id, HrLetterService $letters): void
    {
        $letters->deleteTemplate(LetterTemplate::findOrFail($id), $this->user());
        $this->toast('Template deleted. Letters already issued stay in the documents.');
    }

    public function saveSignatory(HrLetterService $letters): void
    {
        $this->validate(['signatoryName' => ['required', 'string', 'max:120'], 'signatoryDesignation' => ['required', 'string', 'max:120']], [], ['signatoryName' => 'name', 'signatoryDesignation' => 'designation']);
        $letters->saveSettings(['signatory_name' => $this->signatoryName, 'signatory_designation' => $this->signatoryDesignation], $this->user());
        $this->toast('Signature details saved. New letters use them.');
    }

    /** $kind: signature | seal. Saves the chosen image; new letters and certificates print it. */
    public function uploadSignImage(string $kind, HrLetterService $letters): void
    {
        abort_unless(in_array($kind, ['signature', 'seal'], true), 404);
        $field = $kind.'Upload';
        $this->validate([$field => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:2048']], [$field.'.mimes' => 'Upload a PNG or JPG image.'], [$field => $kind === 'seal' ? 'seal' : 'signature']);
        $letters->saveSignImage($kind, $this->{$field}, $this->user());
        $this->{$field} = null;
        $this->toast(($kind === 'seal' ? 'Seal' : 'Signature').' saved. New letters and certificates print it (letters already issued stay as they are).');
    }

    public function removeSignImage(string $kind, HrLetterService $letters): void
    {
        abort_unless(in_array($kind, ['signature', 'seal'], true), 404);
        $letters->saveSignImage($kind, null, $this->user());
        $this->toast(($kind === 'seal' ? 'Seal' : 'Signature').' removed from new letters.');
    }

    /** Takes the text of a Word file into the template editor (HR then adds {placeholders} and saves). */
    public function importWord(): void
    {
        abort_unless(HrLetterService::canManage($this->user()), 403);
        $this->validate(['wordFile' => ['required', 'file', 'mimes:docx', 'max:5120']], ['wordFile.mimes' => 'Choose a Word file saved as .docx.'], ['wordFile' => 'Word file']);
        $html = HrLetterService::bodyFromDocx($this->wordFile->getRealPath());
        if (! str_contains($html, '{signature}')) $html .= '<p>{signature}</p>';
        $this->templateBody = $html;
        if ($this->templateName === '') $this->templateName = trim(pathinfo((string) $this->wordFile->getClientOriginalName(), PATHINFO_FILENAME));
        $this->wordFile = null;
        $this->dispatch('letter-editor-set', model: 'templateBody', html: $html);
        $this->toast('Word file taken in. Put {placeholders} (e.g. {employee_name}, {course_name}) where the employee\'s details go, then save.');
    }

    public function saveCompany(HrLetterService $letters): void
    {
        $this->resetErrorBag();
        $letters->saveCompany($this->company, $this->user());
        $this->toast('Letterhead saved. New letters use it.');
    }

    /** A sample PDF of the template being edited, with the chosen employee's data (nothing is saved). */
    public function samplePdf(HrLetterService $letters)
    {
        $employee = $this->previewEmployeeId ? AttendanceEmployee::find($this->previewEmployeeId) : null;
        if (! $employee) throw ValidationException::withMessages(['previewEmployeeId' => 'Choose an employee for the sample.']);
        $html = $letters->fill($this->templateBody, $employee)['html'];
        $pdf = $letters->pdf($html, ($this->templateName ?: 'Letter').' - sample');

        return response()->streamDownload(fn () => print($pdf), 'Sample - '.preg_replace('/[\\\\\/:*?"<>|]+/', '-', $this->templateName ?: 'Letter').'.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function render(HrLetterService $letters)
    {
        $preview = null;
        if ($this->showForm && $this->previewEmployeeId && ($employee = AttendanceEmployee::find($this->previewEmployeeId))) {
            $preview = $letters->fill($this->templateBody, $employee);
        }

        return $this->page('livewire.lifecycle.letter-templates', [
            'templates' => LetterTemplate::with('editor')->orderByDesc('is_active')->orderBy('name')->get(),
            'categories' => HrLetterService::categories(),
            'placeholders' => HrLetterService::PLACEHOLDERS,
            'companyFields' => HrLetterService::COMPANY_FIELDS,
            'employees' => AttendanceEmployee::where('is_active', true)->orderBy('name')->get(['id', 'name', 'employee_code']),
            'preview' => $preview,
            'signImages' => ['signature' => (bool) $letters->signImagePath('signature'), 'seal' => (bool) $letters->signImagePath('seal')],
        ], 'Letter templates');
    }
}
