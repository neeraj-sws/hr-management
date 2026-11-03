<?php

namespace Tests\Feature;

use App\Livewire\Lifecycle\LetterTemplates;
use App\Livewire\People\EmployeeProfile;
use App\Models\AttendanceEmployee;
use App\Models\EmployeeDocument;
use App\Models\LetterTemplate;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\HrLetterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** HR letter templates: filled with the employee's data, checked / edited, issued as a PDF into their documents. */
class HrLetterTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;
    private User $staff;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-03 11:00:00');
        $this->hr = User::factory()->create(['email' => 'hr-letters@company.test']);
        $this->hr->assignRole('HR');
        $this->staff = User::factory()->create(['email' => 'letter@company.test']);
        $this->staff->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'LT-7', 'name' => 'Riya Sharma', 'designation' => 'Web Designer', 'is_active' => true, 'user_id' => $this->staff->id, 'joined_at' => '2026-11-10', 'daily_work_hours' => 8.5]);
        SalaryProfile::create(['employee_id' => $this->employee->id, 'monthly_salary' => 125000, 'effective_from' => '2026-11-10', 'working_days_per_month' => 25, 'standard_daily_hours' => 8.5]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_five_standard_templates_are_there_and_fill_with_the_employees_data(): void
    {
        $this->assertEqualsCanonicalizing(['offer', 'appointment', 'noc', 'relieving', 'experience_issued'], LetterTemplate::pluck('category')->all());
        $filled = app(HrLetterService::class)->fill('<p>{employee_name} ({employee_code}), {designation}, joins on {joining_date} at {monthly_salary}; exit {exit_date}. {unknown}</p>', $this->employee);
        $this->assertStringContainsString('Riya Sharma (LT-7), Web Designer, joins on 10 November 2026 at Rs. 1,25,000', $filled['html']);
        $this->assertStringContainsString('exit '.HrLetterService::BLANK, $filled['html'], 'Empty in the profile: a blank line for HR to fill');
        $this->assertSame(['Last working day'], $filled['missing']);
        $this->assertStringContainsString('{unknown}', $filled['html']);
        $this->assertSame('<p>Hi <strong>there</strong></p>', HrLetterService::sanitize('<p onclick="x()">Hi <strong>there</strong><script>alert(1)</script></p>'));
    }

    public function test_hr_generates_checks_edits_and_issues_a_letter_into_the_documents(): void
    {
        $page = Livewire::actingAs($this->hr)->test(EmployeeProfile::class, ['employee' => $this->employee])
            ->call('openLetter', 'appointment')->assertSet('letterOpen', true)
            ->assertSet('letterTemplateId', LetterTemplate::where('category', 'appointment')->value('id'));
        $this->assertStringContainsString('Riya Sharma', $page->get('letterBody'));

        $page->set('letterBody', $page->get('letterBody').'<p>Extra line added by HR.</p>')->call('issueLetter')->assertHasNoErrors()->assertSet('letterOpen', false);
        $document = EmployeeDocument::where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame('appointment', $document->category);
        $this->assertSame($this->hr->id, $document->uploaded_by, 'Who issued it');
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($document->storage_path));
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee.letter_issued']);
        $this->assertTrue($this->staff->notifications()->where('data->kind', 'EmployeeDocument')->exists(), 'The employee is told');

        // The employee sees it in their own documents.
        $this->actingAs($this->staff)->get(route('lifecycle.documents'))->assertOk()->assertSee($document->original_name);
        // ...and in the mobile app's Documents.
        \Laravel\Sanctum\Sanctum::actingAs($this->staff);
        $this->getJson('/api/my/documents')->assertOk()->assertJsonFragment(['name' => $document->original_name, 'category' => 'Appointment letter']);
    }

    public function test_employees_cannot_make_letters_or_templates(): void
    {
        Livewire::actingAs($this->staff)->test(EmployeeProfile::class, ['employee' => $this->employee])->call('openLetter', 'appointment')->assertForbidden();
        $this->actingAs($this->staff)->get(route('lifecycle.letters'))->assertForbidden();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(HrLetterService::class)->issue($this->employee, LetterTemplate::firstOrFail(), '<p>Letter</p>', $this->staff);
    }

    public function test_hr_adds_edits_turns_off_and_deletes_templates(): void
    {
        $this->actingAs($this->hr)->get(route('lifecycle.letters'))->assertOk()->assertSee('Appointment letter');
        $page = Livewire::actingAs($this->hr)->test(LetterTemplates::class)->call('create')
            ->set('templateName', 'Internship letter')->set('templateCategory', 'hr_letter')->set('templateBody', '<p>Dear {first_name}, {salary_text}</p>')
            ->call('save')->assertHasErrors('templateBody');
        $page->set('templateBody', '<p>Dear {first_name}, well done.</p><script>x</script>')->call('save')->assertHasNoErrors();
        $template = LetterTemplate::where('name', 'Internship letter')->firstOrFail();
        $this->assertSame('<p>Dear {first_name}, well done.</p>', $template->body);

        $page->call('toggle', $template->id);
        $this->assertFalse($template->fresh()->is_active);
        Livewire::actingAs($this->hr)->test(EmployeeProfile::class, ['employee' => $this->employee])->call('openLetter')
            ->assertViewHas('letterTemplates', fn ($list) => ! $list->contains('id', $template->id));
        $page->call('delete', $template->id);
        $this->assertDatabaseMissing('letter_templates', ['id' => $template->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'letter_template.deleted']);
    }

    public function test_joining_and_exit_shortcuts_open_the_right_letter(): void
    {
        $this->actingAs($this->hr)->get(route('people.employees.show', ['employee' => $this->employee, 'letter' => 'relieving']))->assertOk()->assertSee('Generate letter · Riya Sharma');
        Livewire::withQueryParams(['letter' => 'experience_issued'])->actingAs($this->hr)->test(EmployeeProfile::class, ['employee' => $this->employee])
            ->assertSet('letterOpen', true)->assertSet('initialTab', 'docs')
            ->assertSet('letterTemplateId', LetterTemplate::where('category', 'experience_issued')->value('id'));
    }
}
