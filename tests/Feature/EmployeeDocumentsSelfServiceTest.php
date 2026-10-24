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

        // The photo item sets the profile photo.
        $page->call('create', 'photo')->set('file', $this->fakeImage())->call('saveDocument')->assertHasNoErrors();
        $this->assertNotNull($e->fresh()->photo_path);

        // The photo now shows in "My files" too.
        $page->assertSee('Profile photo')->assertSee('My files (2)');

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
