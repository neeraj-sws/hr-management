<?php

namespace Tests\Feature;

use App\Livewire\Lifecycle\LetterTemplates;
use App\Livewire\People\EmployeeProfile;
use App\Models\AttendanceEmployee;
use App\Models\AuditLog;
use App\Models\EmployeeDocument;
use App\Models\TrainingEnrollment;
use App\Models\User;
use App\Services\HrLetterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Training certificate like the company's Word certificate, issued from the employee's profile with the uploaded
 * signature and seal and the date, saved in the employee's documents. Letters get the signature and seal too.
 */
class TrainingCertificateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'TR-7', 'name' => 'Affan Sheikh', 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2026-06-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A small real PNG (2×2, RGBA). */
    private function png(string $name): UploadedFile
    {
        $chunk = fn (string $t, string $d) => pack('N', strlen($d)).$t.$d.pack('N', crc32($t.$d));
        $raw = str_repeat("\x00".str_repeat("\x10\x20\x90\xff", 2), 2);
        $data = "\x89PNG\r\n\x1A\n".$chunk('IHDR', pack('NNCCCCC', 2, 2, 8, 6, 0, 0, 0)).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');
        return UploadedFile::fake()->createWithContent($name, $data);
    }

    private function uploadSignAndSeal(): void
    {
        Livewire::actingAs($this->admin)->test(LetterTemplates::class)
            ->set('signatureUpload', $this->png('sign.png'))->call('uploadSignImage', 'signature')->assertHasNoErrors()
            ->set('sealUpload', $this->png('seal.png'))->call('uploadSignImage', 'seal')->assertHasNoErrors()
            ->assertSee('On letters');
    }

    public function test_signature_and_seal_are_uploaded_and_printed_on_letters(): void
    {
        $letters = app(HrLetterService::class);
        $this->assertStringNotContainsString('/Im2', $letters->pdf('<p>Hello</p>'), 'No images: letters stay as before');

        $this->uploadSignAndSeal();

        $this->assertNotNull($letters->signImagePath('signature'));
        $this->assertNotNull($letters->signImagePath('seal'));
        $pdf = $letters->pdf('<p>Hello</p><p>{signature}</p>');
        $this->assertStringContainsString('/Im2', $pdf);
        $this->assertStringContainsString('/Im3', $pdf);
        // Saving the signatory name keeps the images.
        Livewire::actingAs($this->admin)->test(LetterTemplates::class)->set('signatoryName', 'Neeraj')->set('signatoryDesignation', 'Director')->call('saveSignatory');
        $this->assertNotNull($letters->signImagePath('seal'));

        Livewire::actingAs($this->admin)->test(LetterTemplates::class)->call('removeSignImage', 'seal');
        $this->assertNull($letters->signImagePath('seal'));
        $this->assertNotNull($letters->signImagePath('signature'));
    }

    public function test_a_non_image_is_refused(): void
    {
        Livewire::actingAs($this->admin)->test(LetterTemplates::class)
            ->set('signatureUpload', UploadedFile::fake()->create('sign.pdf', 10, 'application/pdf'))->call('uploadSignImage', 'signature')
            ->assertHasErrors('signatureUpload');
    }

    public function test_training_certificate_is_filled_from_the_training_and_saved_in_the_documents(): void
    {
        $this->uploadSignAndSeal();
        TrainingEnrollment::create(['employee_id' => $this->employee->id, 'course_name' => 'Web Designing', 'total_fee' => 10000, 'discount' => 0, 'installments' => 1, 'starts_on' => '2026-06-01', 'ends_on' => '2026-10-31', 'status' => 'active', 'created_by' => $this->admin->id]);

        $page = Livewire::actingAs($this->admin)->test(EmployeeProfile::class, ['employee' => $this->employee])
            ->assertSee('Training certificate')
            ->call('openCertificate')
            ->assertSet('cert.name', 'Affan Sheikh')->assertSet('cert.course', 'Web Designing')
            ->assertSet('cert.from', 'June 2026')->assertSet('cert.to', 'October 2026')->assertSet('cert.date', '05 November 2026')
            ->set('cert.salutation', 'Mr.')->set('cert.parent', 'Mr. Sadiq Sheikh')
            ->set('cert.name', '')->call('issueCertificate')->assertHasErrors('cert.name')
            ->set('cert.name', 'Affan Sheikh')->call('issueCertificate')->assertHasNoErrors()->assertSet('certOpen', false);

        $document = EmployeeDocument::where('employee_id', $this->employee->id)->where('category', 'training_certificate')->firstOrFail();
        $this->assertSame('Training certificate - Web Designing - TR-7 - 05 Nov 2026.pdf', $document->original_name);
        $pdf = Storage::disk('local')->get($document->storage_path);
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('/Times-Italic', $pdf);
        $this->assertStringContainsString('/Im2', $pdf, 'signature');
        $this->assertStringContainsString('/Im3', $pdf, 'seal');
        $text = gzuncompress(preg_match('/<< \/Length \d+ \/Filter \/FlateDecode >>\nstream\n(.*)\nendstream/s', $pdf, $m) ? $m[1] : '');
        foreach (['Certificate of Training', '\(Web Designing Training\)', 'Affan Sheikh', 'Mr. Sadiq Sheikh', 'June 2026', 'October 2026', 'Date: 05 November 2026', 'We wish him all the best for his better future.'] as $words) {
            $this->assertStringContainsString($words, $text);
        }
        $this->assertSame(1, AuditLog::where('action', 'employee.certificate_issued')->count());
        $this->assertStringContainsString('training certificate', $this->employee->user->notifications()->first()->data['message']);
    }

    public function test_word_file_becomes_the_template_text(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .'<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr><w:r><w:t>Certificate of Training</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t xml:space="preserve">Mr. </w:t></w:r><w:r><w:rPr><w:b/><w:i/></w:rPr><w:t>Affan Sheikh</w:t></w:r><w:r><w:t xml:space="preserve"> has completed training &amp; more.</w:t></w:r></w:p>'
            .'</w:body></w:document>');
        $zip->close();

        Livewire::actingAs($this->admin)->test(LetterTemplates::class)
            ->call('create')
            ->set('wordFile', UploadedFile::fake()->createWithContent('Training Certificate.docx', file_get_contents($path)))
            ->call('importWord')->assertHasNoErrors()
            ->assertSet('templateName', 'Training Certificate')
            ->assertSet('templateBody', '<h2>Certificate of Training</h2><p>Mr. <strong><em>Affan Sheikh</em></strong> has completed training &amp; more.</p><p>{signature}</p>');
    }
}
