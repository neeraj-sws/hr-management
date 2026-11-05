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
    public const CATEGORIES = ['offer', 'appointment', 'noc', 'relieving', 'experience_issued', 'hr_letter', 'training_certificate'];

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
        'course_name' => 'Training course (latest)',
        'course_start_date' => 'Training start date',
        'course_end_date' => 'Training end date',
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
        $training = \App\Models\TrainingEnrollment::with('course')->where('employee_id', $employee->id)->orderByDesc('starts_on')->orderByDesc('id')->first();

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
            // Training certificate: the employee's latest training course.
            'course_name' => $training?->course?->title ?? ($training?->course_name ?: null),
            'course_start_date' => $date($training?->starts_on),
            'course_end_date' => $date($training?->ends_on),
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

    /** Letterhead fields HR can set on the Letter templates page (shared with Billing > Settings, same saved record). */
    public const COMPANY_FIELDS = ['name' => 'Company name', 'address' => 'Address', 'city' => 'City', 'state' => 'State', 'pin' => 'PIN code', 'phone' => 'Phone', 'email' => 'E-mail', 'website' => 'Website'];

    /** The company details as saved (read directly, so letters work even where the Billing module is not installed). */
    private function companyRecord(): array
    {
        $saved = (array) app(PolicyConfigurationService::class)->forEmployee(null, 'billing.company', today()->toDateString(), []);

        return array_merge(['name' => config('app.name'), 'address' => '', 'city' => '', 'state' => '', 'pin' => '', 'phone' => '', 'email' => '', 'website' => '', 'signatory' => ''], array_filter($saved, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string, string> the letterhead fields for the form */
    public function companyFields(): array
    {
        return array_map(fn ($v) => (string) $v, array_intersect_key($this->companyRecord(), self::COMPANY_FIELDS));
    }

    /** Saves the letterhead details; other saved company details (bank, GST …) are kept as they are. */
    public function saveCompany(array $fields, User $actor): void
    {
        abort_unless(self::canManage($actor), 403);
        $saved = (array) app(PolicyConfigurationService::class)->forEmployee(null, 'billing.company', today()->toDateString(), []);
        $new = array_map(fn ($v) => mb_substr(trim((string) $v), 0, 200), array_intersect_key($fields, self::COMPANY_FIELDS));
        if (($new['name'] ?? '') === '') throw ValidationException::withMessages(['company.name' => 'Enter the company name.']);
        app(PeopleOperationsService::class)->setPolicy('billing.company', 'billing', 'Billing company details', array_merge($saved, $new), null, $actor->id, today()->toDateString());
        $this->audit->record('letters.letterhead_saved', null, ['name' => $new['name']]);
    }

    /** Company name, address and contact line for the letterhead. */
    public function company(): array
    {
        $c = $this->companyRecord();
        $lines = array_values(array_filter([trim((string) $c['address']), trim((string) $c['city']), trim(trim((string) $c['state']).' '.trim((string) $c['pin']))]));
        $contact = implode('  ·  ', array_filter([$c['phone'] ? 'Phone: '.$c['phone'] : '', $c['email'] ? 'E-mail: '.$c['email'] : '', (string) $c['website']]));

        return ['name' => (string) $c['name'], 'address_lines' => $lines, 'contact' => $contact, 'footer' => implode('  ·  ', array_filter([(string) $c['name'], (string) $c['website']])), 'signatory' => (string) $c['signatory']];
    }

    /** Who signs letters (name and designation), set on the Letter templates page. */
    public function settings(): array
    {
        $saved = app(PolicyConfigurationService::class)->forEmployee(null, 'letters.settings', today()->toDateString(), []);

        return ['signatory_name' => (string) (($saved['signatory_name'] ?? '') ?: $this->company()['signatory']), 'signatory_designation' => (string) ($saved['signatory_designation'] ?? 'Authorised Signatory'),
            'signature_path' => (string) ($saved['signature_path'] ?? ''), 'seal_path' => (string) ($saved['seal_path'] ?? ''),
            'certificate_tagline' => (string) ($saved['certificate_tagline'] ?? 'Move to the Next World'), 'certificate_company' => (array) ($saved['certificate_company'] ?? [])];
    }

    public function saveSettings(array $settings, User $actor): void
    {
        abort_unless(self::canManage($actor), 403);
        $current = $this->settings();
        app(PeopleOperationsService::class)->setPolicy('letters.settings', 'documents', 'HR letter signatory', [
            'signatory_name' => trim((string) ($settings['signatory_name'] ?? $current['signatory_name'])), 'signatory_designation' => trim((string) ($settings['signatory_designation'] ?? $current['signatory_designation'])),
            // The signature and seal images stay unless they are changed.
            'signature_path' => (string) ($settings['signature_path'] ?? $current['signature_path']), 'seal_path' => (string) ($settings['seal_path'] ?? $current['seal_path']),
            'certificate_tagline' => (string) ($settings['certificate_tagline'] ?? $current['certificate_tagline']),
            'certificate_company' => (array) ($settings['certificate_company'] ?? $current['certificate_company']),
        ], null, $actor->id, today()->toDateString());
    }

    /**
     * The signature or the company seal (stamp) printed on every letter and certificate: a PNG or JPG. Null removes it.
     * $kind: signature | seal.
     */
    public function saveSignImage(string $kind, ?\Illuminate\Http\UploadedFile $file, User $actor): void
    {
        abort_unless(self::canManage($actor) && in_array($kind, ['signature', 'seal'], true), 403);
        $old = $this->settings()[$kind.'_path'];
        $path = '';
        if ($file) {
            $ext = strtolower((string) $file->getClientOriginalExtension()) === 'png' ? 'png' : 'jpg';
            $path = (string) $file->storeAs('letters', $kind.'-'.Str::lower(Str::random(12)).'.'.$ext, 'local');
            // Check it can really be printed (8-bit, not interlaced), so a letter never fails later.
            if (! $path || ! LetterPdf::loadImage(Storage::disk('local')->path($path))) {
                if ($path) Storage::disk('local')->delete($path);
                throw ValidationException::withMessages([$kind.'Upload' => 'This image cannot be printed on letters. Save it again as a normal PNG or JPG and upload it.']);
            }
        }
        $this->saveSettings([$kind.'_path' => $path], $actor);
        if ($old && $old !== $path) Storage::disk('local')->delete($old);
        $this->audit->record($file ? "letters.{$kind}_uploaded" : "letters.{$kind}_removed", null, ['path' => $path]);
    }

    /** Full path of the signature / seal image, or null when none is set (or the file is gone). */
    public function signImagePath(string $kind): ?string
    {
        $path = $this->settings()[$kind.'_path'] ?? '';

        return $path && Storage::disk('local')->exists($path) ? Storage::disk('local')->path($path) : null;
    }

    /**
     * A Word file (.docx) as a template body: its paragraphs with bold / italic / underline and headings. Pictures,
     * table borders and page layout are not taken over; HR checks it in the editor and adds {placeholders}.
     */
    public static function bodyFromDocx(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true || ($xml = $zip->getFromName('word/document.xml')) === false) {
            throw ValidationException::withMessages(['wordFile' => 'This is not a Word (.docx) file, or it could not be read. Save it in Word as .docx and try again.']);
        }
        $zip->close();
        $dom = new \DOMDocument();
        if (! @$dom->loadXML($xml, LIBXML_NONET)) throw ValidationException::withMessages(['wordFile' => 'The Word file could not be read.']);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $html = '';
        foreach ($xp->query('//w:body//w:p') as $p) {
            $style = (string) $xp->evaluate('string(w:pPr/w:pStyle/@w:val)', $p);
            $runs = '';
            foreach ($xp->query('.//w:r', $p) as $r) {
                $text = '';
                foreach ($r->childNodes as $node) {
                    if ($node->localName === 't') $text .= $node->textContent;
                    elseif ($node->localName === 'tab') $text .= ' ';
                    elseif ($node->localName === 'br') $text .= "\n";
                }
                if ($text === '') continue;
                $text = nl2br(e($text), false);
                $on = fn (string $tag) => $xp->query("w:rPr/w:{$tag}[not(@w:val='0') and not(@w:val='false') and not(@w:val='none')]", $r)->length > 0;
                if ($on('u')) $text = "<u>{$text}</u>";
                if ($on('i')) $text = "<em>{$text}</em>";
                if ($on('b')) $text = "<strong>{$text}</strong>";
                $runs .= $text;
            }
            $tag = preg_match('/^(Heading1|Title)$/i', $style) ? 'h2' : (preg_match('/^Heading[2-9]$/i', $style) ? 'h3' : 'p');
            $html .= "<{$tag}>{$runs}</{$tag}>";
        }
        $html = self::sanitize($html);
        if (trim(html_entity_decode(strip_tags($html))) === '') throw ValidationException::withMessages(['wordFile' => 'The Word file has no text.']);

        return $html;
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

        return (new LetterPdf($company, ['company' => $company['name'], 'name' => $settings['signatory_name'], 'designation' => $settings['signatory_designation']], self::logoPath(), $this->signImagePath('signature'), $this->signImagePath('seal')))
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

    // ---- training certificate ----------------------------------------------------------------

    /** The company details printed on the certificate until HR changes them (from the company's own certificate). */
    public const CERTIFICATE_COMPANY = [
        'company' => 'Soham Web Solution',
        'address1' => '12, Civil Lines, Chamunda Complex, Near BJP Office,',
        'address2' => 'Dewas, Madhya Pradesh, 455001',
        'phone' => '7879231700, 7748018188',
        'email' => 'contact@sohamsolution.com, soham.solution@yahoo.com',
        'website' => 'www.sohamsolution.com',
    ];

    /** What the certificate says, filled from the employee and their latest training (HR can change any of it). */
    public function certificateDefaults(AttendanceEmployee $employee): array
    {
        $training = \App\Models\TrainingEnrollment::with('course')->where('employee_id', $employee->id)->orderByDesc('starts_on')->orderByDesc('id')->first();
        $saved = app(PolicyConfigurationService::class)->forEmployee(null, 'letters.settings', today()->toDateString(), []);

        return [
            'salutation' => '', 'name' => (string) $employee->name, 'relation' => 'Son of', 'parent' => '',
            'course' => (string) ($training?->course?->title ?? $training?->course_name ?? ''),
            'from' => $training?->starts_on?->format('F Y') ?? '', 'to' => $training?->ends_on?->format('F Y') ?? '',
            'date' => today()->format('d F Y'), 'tagline' => (string) ($saved['certificate_tagline'] ?? 'Move to the Next World'),
            // Its own company details (the letterhead is shared with Billing and may name another brand).
            ...array_map('strval', array_merge(self::CERTIFICATE_COMPANY, array_intersect_key((array) ($saved['certificate_company'] ?? []), self::CERTIFICATE_COMPANY))),
        ];
    }

    /** The certificate as a PDF (contents), with the signature and seal when they are uploaded. */
    public function certificatePdf(array $fields): string
    {
        $f = fn (string $key) => trim((string) ($fields[$key] ?? self::CERTIFICATE_COMPANY[$key] ?? ''));

        return (new \App\Support\CertificatePdf(self::logoPath(), $this->signImagePath('signature'), $this->signImagePath('seal')))->render([
            ...array_map(fn ($v) => trim((string) $v), array_intersect_key($fields, array_flip(['salutation', 'name', 'relation', 'parent', 'course', 'from', 'to', 'date', 'tagline']))),
            'company' => $f('company'), 'address_lines' => array_values(array_filter([$f('address1'), $f('address2')])),
            'phone' => $f('phone'), 'email' => $f('email'), 'website' => $f('website'),
        ]);
    }

    /** Saves the certificate in the employee's documents ("Training certificate"); they are told. */
    public function issueCertificate(AttendanceEmployee $employee, array $fields, User $actor): EmployeeDocument
    {
        abort_unless(self::canManage($actor), 403);
        ImpersonationService::block();
        $name = trim(preg_replace('/[\\\\\/:*?"<>|]+/', '-', 'Training certificate - '.trim((string) ($fields['course'] ?? '')).' - '.$employee->employee_code.' - '.today()->format('d M Y'))).'.pdf';
        $path = 'employee-documents/'.$employee->id.'/certificate-'.Str::lower(Str::random(16)).'.pdf';
        if (! Storage::disk('local')->put($path, $this->certificatePdf($fields))) {
            throw ValidationException::withMessages(['cert.name' => 'The PDF could not be saved on the server. Please try again.']);
        }
        try {
            $document = EmployeeDocument::create(['employee_id' => $employee->id, 'uploaded_by' => $actor->id, 'category' => 'training_certificate', 'original_name' => $name, 'storage_path' => $path]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        // The line under the company name and the company details are kept for the next certificate.
        $this->saveSettings(['certificate_tagline' => trim((string) ($fields['tagline'] ?? '')),
            'certificate_company' => array_map(fn ($k) => trim((string) ($fields[$k] ?? '')), array_combine(array_keys(self::CERTIFICATE_COMPANY), array_keys(self::CERTIFICATE_COMPANY)))], $actor);
        $this->audit->record('employee.certificate_issued', $document, ['employee_id' => $employee->id, 'employee' => $employee->name, 'course' => $fields['course'] ?? null, 'from' => $fields['from'] ?? null, 'to' => $fields['to'] ?? null, 'date' => $fields['date'] ?? null, 'name' => $name]);
        Notifier::send($employee->user, 'EmployeeDocument', $document->id, 'HR issued your training certificate ('.trim((string) ($fields['course'] ?? '')).'). You can view and download it in Documents.', route('lifecycle.documents', [], false), $actor->id);

        return $document;
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
