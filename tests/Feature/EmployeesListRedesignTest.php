<?php

namespace Tests\Feature;

use App\Livewire\People\Employees;
use App\Models\AttendanceEmployee;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/** Employees list: sorting, rows per page, reset, and importing employees from a sheet. */
class EmployeesListRedesignTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    public function test_sort_per_page_and_reset(): void
    {
        foreach (['Zara Zed' => 'S-1', 'Amit Able' => 'S-2'] as $name => $code) {
            AttendanceEmployee::create(['employee_code' => $code, 'name' => $name, 'is_active' => true, 'joined_at' => '2026-01-01']);
        }
        $page = Livewire::actingAs($this->admin)->test(Employees::class)->set('search', 'S-')
            ->call('sortBy', 'name')->assertSet('sort', 'name')->assertSet('dir', 'asc')->assertSeeInOrder(['Amit Able', 'Zara Zed'])
            ->call('sortBy', 'name')->assertSet('dir', 'desc')->assertSeeInOrder(['Zara Zed', 'Amit Able'])
            ->assertSee('Showing 1 to 2 of 2 employees');
        $page->set('perPage', 25)->assertSet('perPage', 25)->call('resetFilters')->assertSet('search', '')->assertSet('status', 'active');
    }

    public function test_import_adds_new_people_updates_existing_and_lists_bad_rows(): void
    {
        Department::firstOrCreate(['name' => 'Development'], ['code' => 'DEV-IMP']);
        AttendanceEmployee::create(['employee_code' => 'IMP-1', 'name' => 'Old Name', 'is_active' => true, 'joined_at' => '2026-01-01', 'designation' => 'Tester']);
        $csv = "employee_code,name,email,designation,department,joined_at,work_start,work_end,daily_hours\n"
            ."IMP-1,Updated Name,,Senior Tester,Development,,,,\n"
            ."IMP-2,New Person,new.person@example.com,Developer,development,24-10-2026,10:00,19:00,8.5\n"
            ."IMP-3,No Email,,,,,,,\n"
            ."IMP-4,Bad Dept,bad@example.com,,Nowhere,,,,\n";
        $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

        $page = Livewire::actingAs($this->admin)->test(Employees::class)->call('openImport')->set('importFile', $file)->call('importEmployees')->assertHasNoErrors();
        $result = $page->get('importResult');
        $this->assertSame([1, 1], [$result["created"], $result["updated"]], json_encode($result));
        $this->assertCount(2, $result['errors']);
        $this->assertStringContainsString('Row 4: email is needed', $result['errors'][0]);
        $this->assertStringContainsString('Row 5: department "Nowhere" does not exist', $result['errors'][1]);

        $this->assertSame(['Updated Name', 'Senior Tester'], [AttendanceEmployee::where('employee_code', 'IMP-1')->value('name'), AttendanceEmployee::where('employee_code', 'IMP-1')->value('designation')]);
        $new = AttendanceEmployee::with('user')->where('employee_code', 'IMP-2')->firstOrFail();
        $this->assertSame(['2026-10-24', '10:00', 8.5], [$new->joined_at->toDateString(), substr((string) $new->work_start, 0, 5), (float) $new->daily_work_hours]);
        $this->assertTrue($new->user->hasRole('Employee'));

        $this->get(route('imports.template', ['type' => 'employees', 'format' => 'csv']))->assertOk()->assertSee('employee_code,name,email');
    }

    public function test_employee_cannot_import(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');
        Livewire::actingAs($user)->test(Employees::class)->call('openImport')->assertForbidden();
    }
}
