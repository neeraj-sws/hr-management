<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\EmployeeDocument;
use App\Models\LetterTemplate;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Support\Access;
use App\Support\LetterPdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HR letters: templates with {placeholders} are filled with an employee's real data, HR checks / edits the
 * letter, and it is issued as a PDF on the company letterhead, saved to the employee's documents (they see
 * it on the website and in the app), written to the audit log, and the employee is told.
 */
class HrLetterService
{
    /** Document types a letter can be saved as (labels from the documents list). */
    public const CATEGORIES = ['offer', 'appointment', 'noc', 'relieving', 'experience_issued', 'hr_letter'];

    /** Placeholders HR can use in a template => what they are replaced with. */
    public const PLACEHOLDERS = [
        'employee_name' => 'Full name',
        'first_name' => 'First name',
        'employee_code' => 'Employee code',
        'designation' => 'Designation',
        'department' => 'Department',
        'manager_name' => 'Reporting manager',
        'employee_address' => 'Home address',
        'employment_type' => 'Employment type',
        'joining_date' => 'Joining date',
        'probation_end_date' => 'Probation end date',
        'notice_period' => 'Notice period (e.g. 30 days)',
        'resignation_date' => 'Resignation date',
        'exit_date' => 'Last working day',
        'monthly_salary' => 'Monthly salary',
        'annual_salary' => 'Annual salary (12 × monthly)',
        'company_name' => 'Company name',
        'company_address' => 'Company address',
        'today' => "Today's date",
        'signature' => 'Signature block (put it where it should go; otherwise it comes at the end)',
    ];

    /** Shown in the letter when the employee's profile has no value, so HR sees what to fill in. */
    public const BLANK = '__________';

    /** Tags kept in a letter body; everything else is reduced to its text. */
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'div'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** HR and administrators make templates and issue letters; employees never do. */
    public static function canManage(?User $user): bool
    {
        return $user !== null && ($user->canAny(['lifecycle.manage', 'hr.manage']) || Access::isAdmin($user));
    }

    /** @return array<string, string> category key => label */
    public static function categories(): array
    {
        return array_intersect_key(EmployeeDocumentService::CATEGORIES, array_flip(self::CATEGORIES));
    }

    /** The first active template saved as this document type (onboarding / exit shortcuts). */
    public function templateFor(string $category): ?LetterTemplate
    {
        return LetterTemplate::where('is_active', true)->where('category', $category)->orderBy('id')->first();
    }

    // ---- placeholders ----------------------------------------------------------------------

    /** @return array<string, ?string> placeholder => plain-text value (null when the profile has none) */
    public function values(AttendanceEmployee $employee): array
    {
        $employee->loadMissing(['department', 'manager']);
        $company = $this->company();
        $monthly = $this->monthlySalary($employee);
        $date = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('j F Y') : null;

        return [
            'employee_name' => $employee->name,
            'first_name' => Str::of((string) $employee->name)->trim()->explode(' ')->first() ?: null,
            'employee_code' => $employee->employee_code,
            'designation' => $employee->designation ?: null,
            'department' => $employee->department?->name,
            'manager_name' => $employee->manager?->name,
            'employee_address' => trim((string) $employee->address) ?: null,
            'employment_type' => EmployeeService::EMPLOYMENT_TYPES[$employee->employment_type] ?? null,
            'joining_date' => $date($employee->joined_at),
            'probation_end_date' => $date($employee->probation_ends_on),
            'notice_period' => $employee->notice_period_days !== null ? $employee->notice_period_days.' '.Str::plural('day', (int) $employee->notice_period_days) : null,
            'resignation_date' => $date($employee->resigned_on),
            'exit_date' => $date($employee->exit_date),
            'monthly_salary' => $monthly > 0 ? self::rupees($monthly) : null,
            'annual_salary' => $monthly > 0 ? self::rupees($monthly * 12) : null,
            'company_name' => $company['name'],
            'company_address' => implode(', ', $company['address_lines']) ?: null,
            'today' => today()->format('j F Y'),
        ];
    }

    /**
     * The template body with the employee's data in place of the placeholders ({signature} stays,
     * it marks where the signature goes). Missing values show as a blank line for HR to fill in.
     *
     * @return array{html: string, missing: list<string>}
     */
    public function fill(string $body, AttendanceEmployee $employee): array
    {
        $values = $this->values($employee);
        $missing = [];
        $html = preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($values, &$missing) {
            if (! array_key_exists($m[1], $values)) return $m[0]; // {signature} and unknown words stay as typed
            if ($values[$m[1]] === null || $values[$m[1]] === '') {
                $missing[] = self::PLACEHOLDERS[$m[1]];
                return self::BLANK;
            }

            return nl2br(e($values[$m[1]]), false);
        }, self::sanitize($body));

        return ['html' => $html, 'missing' => array_values(array_unique($missing))];
    }

    /** Monthly salary from the salary profile in force (on the joining day for someone not joined yet). */
    private function monthlySalary(AttendanceEmployee $employee): float
    {
        $on = $employee->joined_at && $employee->joined_at->isFuture() ? $employee->joined_at->toDateString() : today()->toDateString();
        $profile = SalaryProfile::where('employee_id', $employee->id)->whereDate('effective_from', '<=', $on)->orderByDesc('effective_from')->first()
            ?? SalaryProfile::where('employee_id', $employee->id)->orderByDesc('effective_from')->first();

        return (float) ($profile?->monthly_salary ?? $employee->monthly_salary ?? 0);
    }

    /** Rs. in the Indian style: 1250000 → "Rs. 12,50,000" (paise only when there are any). */
    public static function rupees(float $amount): string
    {
        $whole = (string) (int) floor(abs($amount));
        $paise = (int) round((abs($amount) - floor(abs($amount))) * 100);
        $grouped = strlen($whole) > 3 ? preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($whole, 0, -3)).','.substr($whole, -3) : $whole;

        return 'Rs. '.($amount < 0 ? '-' : '').$grouped.($paise ? '.'.str_pad((string) $paise, 2, '0', STR_PAD_LEFT) : '');
    }

    /**
     * Keeps only simple formatting (paragraphs, bold, italic, underline, lists, headings, line breaks and
     * text alignment); scripts, links, images and every other attribute are removed.
     */
    public static function sanitize(string $html): string
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'.$html.'</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $body) return '';
        self::clean($body);
        $out = '';
        foreach ($body->childNodes as $child) $out .= $dom->saveHTML($child);

        return trim($out);
    }

    private static function clean(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment) { $node->removeChild($child); continue; }
            if (! $child instanceof \DOMElement) continue;
            $tag = strtolower($child->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'head', 'title', 'template', 'svg', 'math', 'img', 'input', 'button', 'select', 'textarea', 'form'], true)) {
                $node->removeChild($child);
                continue;
            }
            self::clean($child);
            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                // Unknown tag (span, a, font …): keep its contents.
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            $align = preg_match('/text-align\s*:\s*(left|center|right|justify)/i', (string) $child->getAttribute('style'), $m) ? strtolower($m[1]) : null;
            foreach (iterator_to_array($child->attributes) as $attribute) $child->removeAttribute($attribute->nodeName);
            if ($align && in_array($tag, ['p', 'div', 'h2', 'h3', 'h4', 'li'], true)) $child->setAttribute('style', 'text-align:'.$align);
        }
    }

    // ---- letterhead ------------------------------------------------------------------------

    /** Company name, address and contact line for the letterhead (from Billing > Settings, where they are kept). */
    public function company(): array
    {
        $c = app(BillingService::class)->company();
        $lines = array_values(array_filter([trim((string) $c['address']), trim((string) $c['city']), trim(trim((string) $c['state']).' '.trim((string) $c['pin']))]));
        $contact = implode('  ·  ', array_filter([$c['phone'] ? 'Phone: '.$c['phone'] : '', $c['email'] ? 'E-mail: '.$c['email'] : '', (string) $c['website']]));

        return ['name' => (string) $c['name'], 'address_lines' => $lines, 'contact' => $contact, 'footer' => implode('  ·  ', array_filter([(string) $c['name'], (string) $c['website']])), 'signatory' => (string) $c['signatory']];
    }

    /** Who signs letters (name and designation), set on the Letter templates page. */
    public function settings(): array
    {
        $saved = app(PolicyConfigurationService::class)->forEmployee(null, 'letters.settings', today()->toDateString(), []);

        return ['signatory_name' => (string) (($saved['signatory_name'] ?? '') ?: $this->company()['signatory']), 'signatory_designation' => (string) ($saved['signatory_designation'] ?? 'Authorised Signatory')];
    }

    public function saveSettings(array $settings, User $actor): void
    {
        abort_unless(self::canManage($actor), 403);
        app(PeopleOperationsService::class)->setPolicy('letters.settings', 'documents', 'HR letter signatory', [
            'signatory_name' => trim((string) ($settings['signatory_name'] ?? '')), 'signatory_designation' => trim((string) ($settings['signatory_designation'] ?? '')),
        ], null, $actor->id, today()->toDateString());
    }

    public static function logoPath(): string
    {
        return public_path('images/sws-logo.png');
    }

    /** The letter as a PDF file (contents). */
    public function pdf(string $html, string $title = 'Letter'): string
    {
        $company = $this->company();
        $settings = $this->settings();

        return (new LetterPdf($company, ['company' => $company['name'], 'name' => $settings['signatory_name'], 'designation' => $settings['signatory_designation']], self::logoPath()))
            ->render(self::sanitize($html), $title);
    }

    // ---- templates ---------------------------------------------------------------------------

    /** Creates or changes a template (HR / admin). The body keeps only simple formatting. */
    public function saveTemplate(?LetterTemplate $template, array $data, User $actor): LetterTemplate
    {
        abort_unless(self::canManage($actor), 403);
        $name = trim((string) ($data['name'] ?? ''));
        $category = (string) ($data['category'] ?? 'hr_letter');
        $body = self::sanitize((string) ($data['body'] ?? ''));
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) $errors['templateName'] = 'Give the template a name (up to 120 characters).';
        if (! in_array($category, self::CATEGORIES, true)) $errors['templateCategory'] = 'Choose what the letter is saved as.';
        if (trim(html_entity_decode(strip_tags($body))) === '') $errors['templateBody'] = 'Write the letter.';
        $unknown = collect(self::placeholdersIn($body))->reject(fn ($p) => array_key_exists($p, self::PLACEHOLDERS))->values();
        if ($unknown->isNotEmpty()) $errors['templateBody'] = 'Unknown placeholder: {'.$unknown->join('}, {').'}. Use the "Insert placeholder" list.';
        if ($errors) throw ValidationException::withMessages($errors);

        $values = ['name' => $name, 'category' => $category, 'body' => $body, 'updated_by' => $actor->id];
        if ($template) {
            $template->update($values);
            $this->audit->record('letter_template.updated', $template, ['name' => $name]);
        } else {
            $template = LetterTemplate::create([...$values, 'is_active' => true, 'created_by' => $actor->id]);
            $this->audit->record('letter_template.created', $template, ['name' => $name]);
        }

        return $template->fresh();
    }

    /** A template that is off is kept but not offered when making a letter. */
    public function toggleTemplate(LetterTemplate $template, User $actor): LetterTemplate
    {
        abort_unless(self::canManage($actor), 403);
        $template->update(['is_active' => ! $template->is_active, 'updated_by' => $actor->id]);
        $this->audit->record($template->is_active ? 'letter_template.turned_on' : 'letter_template.turned_off', $template, ['name' => $template->name]);

        return $template;
    }

    /** Letters already issued are PDFs in the employees' documents, so they stay. */
    public function deleteTemplate(LetterTemplate $template, User $actor): void
    {
        abort_unless(self::canManage($actor), 403);
        $this->audit->record('letter_template.deleted', $template, ['name' => $template->name]);
        $template->delete();
    }

    /** @return list<string> placeholder names used in a body, e.g. ["employee_name", "today"] */
    public static function placeholdersIn(string $body): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $body, $m);

        return array_values(array_unique($m[1]));
    }

    // ---- issuing -----------------------------------------------------------------------------

    /** Saves the (checked / edited) letter as a PDF in the employee's documents. */
    public function issue(AttendanceEmployee $employee, LetterTemplate $template, string $html, User $actor): EmployeeDocument
    {
        abort_unless(self::canManage($actor), 403);
        ImpersonationService::block();
        $html = self::sanitize($html);
        if (trim(html_entity_decode(strip_tags($html))) === '') throw ValidationException::withMessages(['letterBody' => 'The letter is empty.']);
        $category = in_array($template->category, self::CATEGORIES, true) ? $template->category : 'hr_letter';
        $name = trim(preg_replace('/[\\\\\/:*?"<>|]+/', '-', $template->name.' - '.$employee->employee_code.' - '.today()->format('d M Y'))).'.pdf';
        $path = 'employee-documents/'.$employee->id.'/letter-'.Str::lower(Str::random(16)).'.pdf';
        if (! Storage::disk('local')->put($path, $this->pdf($html, $template->name.' - '.$employee->name))) {
            throw ValidationException::withMessages(['letterBody' => 'The PDF could not be saved on the server. Please try again.']);
        }
        try {
            $document = EmployeeDocument::create(['employee_id' => $employee->id, 'uploaded_by' => $actor->id, 'category' => $category, 'original_name' => $name, 'storage_path' => $path]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        $this->audit->record('employee.letter_issued', $document, ['employee_id' => $employee->id, 'employee' => $employee->name, 'template_id' => $template->id, 'template' => $template->name, 'category' => $category, 'name' => $name]);
        Notifier::send($employee->user, 'EmployeeDocument', $document->id, "HR issued your {$template->name}. You can view and download it in Documents.", route('lifecycle.documents', [], false), $actor->id);

        return $document;
    }
}
