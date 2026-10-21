<?php

namespace Tests\Feature;

use App\Livewire\People\EmployeeProfile;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\DailyWorkStatus;
use App\Models\EmployeeDocument;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** 21 Oct batch C: reports, weekly off over leave, team chart, photo, daily status / compliance history, documents. */
class Oct21BatchCTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-21 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $code, array $extra = []): AttendanceEmployee
    {
        $user = User::factory()->create(['email' => strtolower($code).'@example.com']);
        $user->assignRole('Employee');

        return AttendanceEmployee::create(['employee_code' => $code, 'name' => "Person {$code}", 'email' => $user->email, 'is_active' => true, 'user_id' => $user->id, 'joined_at' => '2024-01-01', 'work_start' => '10:00:00', 'work_end' => '19:00:00', ...$extra]);
    }

    public function test_employees_report_is_in_code_order_and_marks_ex_employees(): void
    {
        $this->employee('EMP-10');
        $this->employee('EMP-2', ['is_active' => false, 'exit_date' => '2026-05-31', 'exit_type' => 'resignation']);
        $this->employee('EMP-9', ['is_active' => false]);

        $rows = app(ReportService::class)->rows($this->admin, 'employees', ['from' => '2026-10-01', 'to' => '2026-10-21'])->whereIn('employee_code', ['EMP-2', 'EMP-9', 'EMP-10'])->values();
        $this->assertSame(['EMP-2', 'EMP-9', 'EMP-10'], $rows->pluck('employee_code')->all());
        $this->assertSame(['ex-employee', 'inactive', 'active'], $rows->pluck('status')->all());
        $this->assertSame('2026-05-31', $rows[0]['left_on']);
    }

    public function test_salary_report_has_one_row_per_month(): void
    {
        $e = $this->employee('SAL-1');
        SalaryProfile::create(['employee_id' => $e->id, 'monthly_salary' => 10000, 'effective_from' => '2024-02-12', 'working_days_per_month' => 25, 'standard_daily_hours' => 8.5]);
        SalaryProfile::create(['employee_id' => $e->id, 'monthly_salary' => 12000, 'effective_from' => '2026-07-01', 'working_days_per_month' => 25, 'standard_daily_hours' => 8.5]);

        $rows = app(ReportService::class)->rows($this->admin, 'salary', ['from' => '2026-01-01', 'to' => '2026-10-21', 'employee_id' => $e->id]);
        $this->assertCount(10, $rows, 'January to October');
        $this->assertSame('Oct 2026', $rows->first()['month']);
        $this->assertSame(12000.0, $rows->first()['monthly_salary']);
        $this->assertSame(10000.0, $rows->firstWhere('month', 'Jun 2026')['monthly_salary']);
        $this->assertSame('not run', $rows->first()['payroll_status']);
    }

    public function test_weekly_off_inside_a_leave_shows_as_weekly_off(): void
    {
        $e = $this->employee('LV-1');
        LeaveRequest::create(['employee_id' => $e->id, 'leave_type_id' => LeaveType::firstOrFail()->id, 'starts_on' => '2026-10-16', 'ends_on' => '2026-10-19', 'days' => 2, 'duration' => 'full', 'reason' => 'Trip', 'status' => 'approved']);

        $days = app(AttendanceService::class)->days($e, Carbon::parse('2026-10-16'), Carbon::parse('2026-10-19'))->keyBy('date');
        $this->assertSame('on_leave', $days['2026-10-16']['status']);
        $this->assertSame('weekend', $days['2026-10-17']['status'], 'Saturday is off with the default calendar');
        $this->assertSame('weekend', $days['2026-10-18']['status'], 'Sunday stays a weekly off');
        $this->assertSame('on_leave', $days['2026-10-19']['status']);
    }

    public function test_profile_shows_team_chart_compliance_history_daily_status_and_documents(): void
    {
        Storage::fake('local');
        $lead = $this->employee('MG-1', ['designation' => 'Lead']);
        $member = $this->employee('MG-2', ['manager_id' => $lead->id, 'designation' => 'Developer']);
        // Late on Mon 19 Oct, left early, no daily status; on Tue 20 Oct a status submitted the next day.
        AttendanceSession::create(['employee_id' => $member->id, 'work_date' => '2026-10-19', 'check_in' => '2026-10-19 10:40:00', 'check_out' => '2026-10-19 17:00:00', 'status' => 'completed']);
        AttendanceSession::create(['employee_id' => $member->id, 'work_date' => '2026-10-20', 'check_in' => '2026-10-20 09:55:00', 'check_out' => '2026-10-20 19:05:00', 'status' => 'completed']);
        DailyWorkStatus::create(['employee_id' => $member->id, 'work_date' => '2026-10-20', 'summary' => 'Built the login page', 'submitted_at' => '2026-10-21 09:00:00']);
        Storage::disk('local')->put('employee-documents/offer.pdf', '%PDF-1.4 test');
        $doc = EmployeeDocument::create(['employee_id' => $member->id, 'uploaded_by' => $this->admin->id, 'category' => 'appointment', 'original_name' => 'appointment.pdf', 'storage_path' => 'employee-documents/offer.pdf']);

        Livewire::actingAs($this->admin)->test(EmployeeProfile::class, ['employee' => $lead])
            ->assertSee('Team')->assertSee('Person MG-2')->assertSee('Developer');

        Livewire::actingAs($this->admin)->test(EmployeeProfile::class, ['employee' => $member])
            ->assertSee('Late arrival')->assertSee('Early check-out')->assertSee('Daily status missing')->assertSee('Daily status late')
            ->assertSee('Built the login page')
            ->assertSee('appointment.pdf')->assertSee('Appointment letter')->assertSee(route('hr.documents.view', $doc), false);

        $this->actingAs($this->admin)->get(route('hr.documents.view', $doc))->assertOk()->assertHeader('content-disposition');
    }

    public function test_photo_can_be_added_and_is_shown_to_signed_in_users(): void
    {
        Storage::fake('local');
        $e = $this->employee('PH-1');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAX+XDSwAAAABJRU5ErkJggg==');

        Livewire::actingAs($e->user)->test(\App\Livewire\Account\Profile::class)
            ->set('photo', UploadedFile::fake()->createWithContent('me.png', $png))->assertHasNoErrors();
        $e->refresh();
        $this->assertNotNull($e->photo_path);
        Storage::disk('local')->assertExists($e->photo_path);

        $this->actingAs($this->admin)->get($e->photoUrl())->assertOk();
        Livewire::actingAs($this->admin)->test(EmployeeProfile::class, ['employee' => $e])->assertSee($e->photoUrl(), false)
            ->call('removePhoto');
        $this->assertNull($e->fresh()->photo_path);
    }
}
