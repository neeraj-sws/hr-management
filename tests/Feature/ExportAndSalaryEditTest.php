<?php

namespace Tests\Feature;

use App\Livewire\Payroll\Salary;
use App\Models\AttendanceEmployee;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Support\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class ExportAndSalaryEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->employee = AttendanceEmployee::create(['employee_code' => 'EXP1', 'name' => 'Export One', 'is_active' => true, 'work_start' => '09:30:00', 'work_end' => '18:30:00', 'joined_at' => '2026-01-01', 'monthly_salary' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_xlsx_writer_produces_a_readable_workbook(): void
    {
        $path = XlsxWriter::toTempFile(['Name', 'Amount'], [['Asha', 50000], ['Ravi "R"', 42000.5]], 'Salary');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('Asha', $sheet);
        $this->assertStringContainsString('<v>50000</v>', $sheet);          // numeric cell
        $this->assertStringContainsString('Ravi &quot;R&quot;', $sheet);     // escaped text
        $this->assertStringContainsString('<v>42000.5</v>', $sheet);
    }

    public function test_attendance_and_salary_reports_export_as_csv_and_xlsx(): void
    {
        SalaryProfile::create(['employee_id' => $this->employee->id, 'effective_from' => '2026-01-01', 'monthly_salary' => 50000, 'working_days_per_month' => 22, 'standard_daily_hours' => 8, 'configured_by' => $this->admin->id]);
        $this->actingAs($this->admin);

        foreach (['attendance', 'salary'] as $type) {
            $csv = $this->get("/reports/{$type}/export?from=2026-10-01&to=2026-10-12");
            $csv->assertOk();
            $this->assertStringContainsString('text/csv', $csv->headers->get('content-type'));

            $xlsx = $this->get("/reports/{$type}/export?format=xlsx&from=2026-10-01&to=2026-10-12");
            $xlsx->assertOk();
            $this->assertStringContainsString('spreadsheetml.sheet', $xlsx->headers->get('content-type'));
            $this->assertStringContainsString('.xlsx', $xlsx->headers->get('content-disposition'));
        }
    }

    public function test_reports_go_month_by_month_or_a_custom_range(): void
    {
        $this->actingAs($this->admin);
        // Today is 12 Oct 2026: this month runs to today, an earlier month is the whole month.
        \Livewire\Livewire::test(\App\Livewire\Reports\Report::class, ['type' => 'leave'])
            ->assertSet('month', '2026-10')->assertSet('from', '2026-10-01')->assertSet('to', '2026-10-12')
            ->set('month', '2026-08')->assertSet('from', '2026-08-01')->assertSet('to', '2026-08-31')->assertSee('01 Aug – 31 Aug 2026')
            ->set('month', 'custom')->set('from', '2026-07-15')->set('to', '2026-09-10')->assertSee('Custom range')->assertSet('from', '2026-07-15');
        // A link with any dates opens as a custom range.
        \Livewire\Livewire::withQueryParams(['from' => '2026-09-03', 'to' => '2026-09-20'])->test(\App\Livewire\Reports\Report::class, ['type' => 'audit'])->assertSet('month', 'custom')->assertSet('to', '2026-09-20');
        \Livewire\Livewire::withQueryParams(['from' => '2026-09-01', 'to' => '2026-09-30'])->test(\App\Livewire\Reports\Report::class, ['type' => 'payments'])->assertSet('month', '2026-09');
    }

    public function test_reports_show_a_serial_number_instead_of_the_id(): void
    {
        $this->actingAs($this->admin);
        $type = \App\Models\LeaveType::firstOrFail();
        foreach (['2026-10-05', '2026-10-07'] as $day) {
            \App\Models\LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $type->id, 'starts_on' => $day, 'ends_on' => $day, 'days' => 1, 'status' => 'pending', 'reason' => 'x']);
        }
        $reports = app(\App\Services\ReportService::class);
        $this->assertSame('s_no', $reports->columns('leave')[0]);
        $this->assertNotContains('id', $reports->columns('leave'));
        $this->assertSame('S. No', $reports->heading('leave', 's_no'));
        $this->assertSame([1, 2], $reports->rows($this->admin, 'leave', ['from' => '2026-10-01', 'to' => '2026-10-12'])->pluck('s_no')->all());

        $csv = $this->get('/reports/leave/export?from=2026-10-01&to=2026-10-12')->streamedContent();
        $this->assertStringContainsString('"S. No",', $csv);
        $this->assertMatchesRegularExpression('/^1,/m', $csv);
    }

    public function test_edit_corrects_the_current_salary_in_place_with_validation(): void
    {
        // A wrong salary was entered.
        SalaryProfile::create(['employee_id' => $this->employee->id, 'effective_from' => '2026-10-01', 'monthly_salary' => 5000, 'working_days_per_month' => 22, 'standard_daily_hours' => 8, 'configured_by' => $this->admin->id]);

        // Edit (correct) loads that record's actual effective date, not start-of-month-today.
        $component = Livewire::actingAs($this->admin)->test(Salary::class)
            ->call('correctProfile', $this->employee->id)
            ->assertSet('correcting', true)
            ->assertSet('effectiveFrom', '2026-10-01')
            ->assertSet('monthly', '5000.00');

        // Validation still applies.
        $component->set('monthly', '-1')->call('saveProfile')->assertHasErrors('monthly');

        // Correcting overwrites the same record (no new revision) and updates the employee.
        $component->set('monthly', '50000')->call('saveProfile')->assertHasNoErrors();
        $this->assertSame(1, SalaryProfile::where('employee_id', $this->employee->id)->count(), 'Correction updates in place, no new row');
        $this->assertEquals(50000, (float) SalaryProfile::where('employee_id', $this->employee->id)->value('monthly_salary'));
        $this->assertEquals(50000, (float) $this->employee->fresh()->monthly_salary);
    }

    public function test_revise_adds_a_new_effective_dated_record(): void
    {
        SalaryProfile::create(['employee_id' => $this->employee->id, 'effective_from' => '2026-01-01', 'monthly_salary' => 40000, 'working_days_per_month' => 22, 'standard_daily_hours' => 8, 'configured_by' => $this->admin->id]);

        Livewire::actingAs($this->admin)->test(Salary::class)
            ->call('editProfile', $this->employee->id)
            ->assertSet('correcting', false)
            ->assertSet('effectiveFrom', '2026-10-01')   // start of this month
            ->set('monthly', '45000')->call('saveProfile')->assertHasNoErrors();

        $this->assertSame(2, SalaryProfile::where('employee_id', $this->employee->id)->count(), 'Revise keeps history as a new record');
    }
}
