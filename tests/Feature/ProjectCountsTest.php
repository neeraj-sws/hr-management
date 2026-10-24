<?php

namespace Tests\Feature;

use App\Livewire\Hr\Dashboard;
use App\Livewire\Work\Projects;
use App\Models\AttendanceEmployee;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Project counts on the dashboard (company / team / own), projects page, employee profile and the project-wise report. */
class ProjectCountsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $managerUser;
    private User $employeeUser;
    private AttendanceEmployee $manager;
    private AttendanceEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->managerUser = User::factory()->create();
        $this->managerUser->assignRole('Manager');
        $this->manager = AttendanceEmployee::create(['employee_code' => 'PC-M', 'name' => 'Priya Manager', 'is_active' => true, 'user_id' => $this->managerUser->id]);
        $this->employeeUser = User::factory()->create();
        $this->employeeUser->assignRole('Employee');
        $this->employee = AttendanceEmployee::create(['employee_code' => 'PC-E', 'name' => 'Pavan Employee', 'is_active' => true, 'user_id' => $this->employeeUser->id, 'manager_id' => $this->manager->id]);
        $outsider = AttendanceEmployee::create(['employee_code' => 'PC-O', 'name' => 'Other Person', 'is_active' => true]);

        // Team: one managed (active), one where the report has a task (on hold). Outside the team: two more.
        Project::create(['name' => 'Portal', 'status' => 'active', 'manager_id' => $this->manager->id]);
        $billing = Project::create(['name' => 'Billing', 'status' => 'on_hold']);
        Task::create(['title' => 'Invoices', 'project_id' => $billing->id, 'assignee_id' => $this->employee->id, 'status' => 'completed', 'priority' => 'normal', 'estimated_hours' => 4, 'completed_at' => now()]);
        Task::create(['title' => 'Refunds', 'project_id' => $billing->id, 'assignee_id' => $this->employee->id, 'status' => 'todo', 'priority' => 'normal', 'due_on' => '2026-10-01', 'estimated_hours' => 6]);
        $crm = Project::create(['name' => 'CRM', 'status' => 'active', 'manager_id' => $outsider->id]);
        $crm->members()->attach($outsider->id);
        Project::create(['name' => 'Old site', 'status' => 'completed', 'starts_on' => '2025-01-01', 'ends_on' => '2025-06-30']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_shows_company_team_and_own_project_counts(): void
    {
        Livewire::actingAs($this->admin)->test(Dashboard::class)
            ->assertViewHas('projectScope', 'company')
            ->assertViewHas('projectCounts', fn ($c) => $c['total'] === 4 && $c['active'] === 2 && $c['on_hold'] === 1 && $c['completed'] === 1)
            ->assertSee('Active projects');
        Livewire::actingAs($this->managerUser)->test(Dashboard::class)
            ->assertViewHas('projectScope', 'team')
            ->assertViewHas('projectCounts', fn ($c) => $c['total'] === 2 && $c['active'] === 1 && $c['on_hold'] === 1)
            ->assertSee('Team active projects');
        Livewire::actingAs($this->employeeUser)->test(Dashboard::class)
            ->assertViewHas('projectScope', 'mine')
            ->assertViewHas('projectCounts', fn ($c) => $c['total'] === 1 && $c['on_hold'] === 1)
            ->assertSee('My active projects');
    }

    public function test_projects_page_shows_counts_by_status(): void
    {
        $this->actingAs($this->admin)->get(route('work.projects'))->assertOk()->assertSee('projects')->assertSeeInOrder(['4', 'All', '0', 'Planned', '2', 'Active', '1', 'On hold', '1', 'Completed']);
        Livewire::actingAs($this->admin)->test(Projects::class)->set('status', 'active')
            ->assertViewHas('counts', fn ($c) => $c['total'] === 4)->assertSee('Portal')->assertDontSee('Old site');
    }

    public function test_employee_profile_shows_their_projects(): void
    {
        $this->actingAs($this->admin)->get(route('people.employees.show', $this->employee))->assertOk()
            ->assertSee('Projects (1)')->assertSee('Billing')->assertSee('2 tasks (1 open)');
    }

    public function test_project_wise_report_lists_projects_in_scope(): void
    {
        $reports = app(ReportService::class);
        $all = $reports->rows($this->admin, 'projects', ['from' => '2026-10-01', 'to' => '2026-10-31'])->keyBy('project');
        $this->assertSame($all->pluck('id')->sort()->values()->all(), $all->pluck('id')->values()->all(), 'Listed by ID (1, 2, 3 …)');
        $this->assertEqualsCanonicalizing(['CRM', 'Portal', 'Billing'], $all->keys()->all(), 'A project finished in 2025 is outside October');
        $billing = $all['Billing'];
        $this->assertSame([2, 1, 1, 1, 50], [$billing['tasks'], $billing['completed_tasks'], $billing['open_tasks'], $billing['overdue_tasks'], $billing['progress_pct']]);
        $this->assertSame('10:00:00', $billing['estimated_time']);

        $team = $reports->rows($this->managerUser, 'projects', ['from' => '2026-10-01', 'to' => '2026-10-31'])->pluck('project')->sort()->values()->all();
        $this->assertSame(['Billing', 'Portal'], $team);
        $this->actingAs($this->admin)->get(route('reports.show', 'projects'))->assertOk()->assertSee('Projects report');
        $this->actingAs($this->admin)->get(route('hr.reports.export', ['type' => 'projects', 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk();
    }
}
