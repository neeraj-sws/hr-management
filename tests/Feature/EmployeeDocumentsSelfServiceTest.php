<?php

namespace Tests\Feature;

use App\Livewire\Lifecycle\Documents;
use App\Models\AttendanceEmployee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\EmployeeDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** Employees hand in their own onboarding papers (web and app); HR picks which are required. */
class EmployeeDocumentsSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function employee(): AttendanceEmployee
    {
        $user = User::factory()->create(['email' => 'docs-emp@example.com']);
        $user->assignRole('Employee');

        return AttendanceEmployee::create(['employee_code' => 'DOC-1', 'name' => 'Docs Person', 'email' => $user->email, 'user_id' => $user->id, 'is_active' => true, 'joined_at' => '2026-01-01']);
    }

    public function test_employee_uploads_own_papers_on_the_web_and_hr_is_told(): void
    {
        $e = $this->employee();
        $page = Livewire::actingAs($e->user)->test(Documents::class)
            ->assertSee('Onboarding documents')->assertSee('Resume / CV')->assertSee('7 documents left.')->assertSee('0 / 7')->assertSee('Missing (7)')->assertSee('Front and back, PDF or JPG');

        $page->call('create', 'resume')->assertSet('documentCategory', 'resume')
            ->set('file', UploadedFile::fake()->create('resume.pdf', 120, 'application/pdf'))
            ->call('saveDocument')->assertHasNoErrors()->assertSee('6 documents left.')->assertSee('1 / 7')->assertSee('Done (1)')->assertSee('resume.pdf')->assertSee('My files (1)');
        $doc = EmployeeDocument::where('employee_id', $e->id)->sole();
        $this->assertSame('resume', $doc->category);
        $this->assertSame($e->user->id, $doc->uploaded_by);
        $this->assertTrue($this->admin->notifications()->get()->contains(fn ($n) => str_contains($n->data['message'] ?? '', 'Docs Person uploaded: Resume / CV')));

        // The photo item is a document: listed and viewable, and the profile picture is NOT changed.
        $page->call('create', 'photo')->set('file', $this->fakeImage())->call('saveDocument')->assertHasNoErrors();
        $this->assertNull($e->fresh()->photo_path);
        $this->assertSame(1, EmployeeDocument::where('employee_id', $e->id)->where('category', 'photo')->count());
        $page->assertSee('My files (2)')->assertSeeText('Photo · 1 file');

        // Company papers (appointment letter …) are HR-only; another employee's id is ignored.
        $page->call('create')->set('documentCategory', 'appointment')->set('file', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'))->call('saveDocument')->assertHasErrors('documentCategory');

        // The employee can delete their own upload.
        $page->call('delete', $doc->id);
        $this->assertModelMissing($doc);
    }

    public function test_hr_chooses_the_required_list_and_sees_each_employees_checklist(): void
    {
        $e = $this->employee();
        Livewire::actingAs($this->admin)->test(Documents::class)
            ->call('editRequiredList')->assertSet('required', EmployeeDocumentService::DEFAULT_REQUIRED)
            ->set('required', ['resume', 'pan'])->call('saveRequired');
        $this->assertSame(['resume', 'pan'], app(EmployeeDocumentService::class)->required());

        Livewire::actingAs($this->admin)->test(Documents::class)->set('employee', $e->id)
            ->assertSee('Onboarding documents · Docs Person')->assertSee('2 documents left.')->assertSee('0 / 2');
    }

    public function test_app_can_view_a_pdf_without_downloading_it(): void
    {
        $e = $this->employee();
        Sanctum::actingAs($e->user, ['*']);
        $this->post('/api/my/documents', ['category' => 'resume', 'file' => UploadedFile::fake()->create('cv.pdf', 30, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/my/documents', ['category' => 'other', 'file' => UploadedFile::fake()->create('notes.docx', 30, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')], ['Accept' => 'application/json'])->assertCreated();
        $docs = collect($this->getJson('/api/my/documents')->json('data'))->keyBy('name');
        $this->assertSame('pdf', $docs['cv.pdf']['file_type']);
        $this->assertNull($docs['notes.docx']['view_url'], 'Word files are download only');

        $viewer = $this->get($docs['cv.pdf']['view_url'])->assertOk()->assertSee('pdf.min.js')->assertSee('Download');
        preg_match('/getDocument\(("[^"]+")/', $viewer->getContent(), $m);
        $inline = $this->get(json_decode($m[1]))->assertOk();
        $this->assertStringStartsWith('inline', (string) $inline->headers->get('content-disposition'));
        $this->get(str_replace('signature=', 'signature=x', $docs['cv.pdf']['view_url']))->assertForbidden();
    }

    public function test_aadhaar_uploaded_as_identity_proof_ticks_off_the_checklist(): void
    {
        $e = $this->employee();
        $service = app(EmployeeDocumentService::class);
        $done = fn () => collect($service->checklist($e->fresh()))->pluck('done', 'key');
        $this->assertFalse($done()['aadhaar']);

        // A passport under Identity proof does not count as Aadhaar or PAN.
        $service->store($e, 'identity', UploadedFile::fake()->create('passport.pdf', 20, 'application/pdf'), $e->user);
        $this->assertFalse($done()['aadhaar']);
        $this->assertFalse($done()['pan']);

        $service->store($e, 'identity', UploadedFile::fake()->create('khushboo-aadhar.jpg', 20, 'image/jpeg'), $e->user);
        $this->assertTrue($done()['aadhaar']);
        $this->assertFalse($done()['pan']);

        Livewire::actingAs($e->user)->test(Documents::class)->assertSeeText('Aadhaar card · 1 file')->assertDontSee('Front and back, PDF or JPG');
    }

    public function test_app_sets_the_profile_picture_on_its_own(): void
    {
        $e = $this->employee();
        Sanctum::actingAs($e->user, ['*']);
        $this->post('/api/me/photo', ['photo' => $this->fakeImage()], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('message', 'Profile picture updated.');
        $this->assertNotNull($e->fresh()->photo_path);
        $this->assertSame(0, EmployeeDocument::where('employee_id', $e->id)->count(), 'Not a document');
        $this->assertTrue(collect(app(EmployeeDocumentService::class)->checklist($e->fresh()))->firstWhere('key', 'photo')['done'], 'A profile picture also ticks off the onboarding photo');
        $this->post('/api/me/photo', ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->deleteJson('/api/me/photo')->assertOk();
        $this->assertNull($e->fresh()->photo_path);
    }

    public function test_app_can_send_the_profile_photo_as_base64(): void
    {
        $e = $this->employee();
        Sanctum::actingAs($e->user, ['*']);
        $png = base64_encode((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $this->postJson('/api/me/photo', ['photo_base64' => 'data:image/png;base64,'.$png])->assertOk()->assertJsonPath('message', 'Profile picture updated.');
        $first = $e->fresh()->photo_path;
        $this->assertNotNull($first);
        Storage::disk('local')->assertExists($first);
        // A new photo gets a new address, so the phone shows it instead of the cached one.
        $url = $this->getJson('/api/me')->json('data.photo_url') ?? $this->getJson('/api/me')->json('photo_url');
        $this->postJson('/api/me/photo', ['photo_base64' => $png])->assertOk();
        $this->assertNotSame($first, $e->fresh()->photo_path);
        $this->assertNotSame($url, $this->getJson('/api/me')->json('data.photo_url') ?? $this->getJson('/api/me')->json('photo_url'));
        // Not an image, or not base64: refused with a clear message.
        $this->postJson('/api/me/photo', ['photo_base64' => base64_encode('%PDF-1.4 not a photo')])->assertUnprocessable()->assertJsonPath('errors.photo.0', 'Use a JPG, PNG or WebP photo.');
        $this->postJson('/api/me/photo', ['photo_base64' => '***'])->assertUnprocessable();
    }

    public function test_mobile_app_lists_the_checklist_and_uploads(): void
    {
        $e = $this->employee();
        Sanctum::actingAs($e->user, ['*']);
        $this->getJson('/api/my/documents')->assertOk()
            ->assertJsonPath('checklist.0.key', 'photo')->assertJsonPath('checklist.1.done', false)
            ->assertJsonFragment(['key' => 'aadhaar', 'label' => 'Aadhaar card']);

        $this->post('/api/my/documents', ['category' => 'aadhaar', 'file' => UploadedFile::fake()->create('aadhaar.jpg', 50, 'image/jpeg')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('message', 'Uploaded — HR has been told.');
        $doc = EmployeeDocument::where('employee_id', $e->id)->sole();
        $this->getJson('/api/my/documents')->assertJsonPath('data.0.can_delete', true)
            ->assertJson(fn ($json) => $json->where('checklist', fn ($list) => collect($list)->firstWhere('key', 'aadhaar')['done'] === true)->etc());

        $this->post('/api/my/documents', ['category' => 'noc', 'file' => UploadedFile::fake()->create('n.pdf', 5, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->deleteJson("/api/my/documents/{$doc->id}")->assertOk();
        $this->assertModelMissing($doc);
    }
}
