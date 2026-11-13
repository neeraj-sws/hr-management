<?php

namespace Tests\Feature;

use App\Models\AttendanceEmployee;
use App\Models\BillingCustomer;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\TimesheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Client portal: client logins see only their projects' timesheets, object to lines, approve the month; staff answer and invoice. */
class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Project $project;
    private Client $client;
    private User $clientUser;
    private AttendanceEmployee $dev;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-30 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $devUser = User::factory()->create(['email' => 'khushboo@example.com']);
        $devUser->assignRole('Employee');
        $this->dev = AttendanceEmployee::create(['employee_code' => 'EMP-02', 'name' => 'Khushboo Verma', 'is_active' => true, 'user_id' => $devUser->id, 'joined_at' => '2026-01-01']);
        $this->project = Project::create(['name' => 'Source India', 'status' => 'active', 'hourly_rate' => 600]);
        $customer = BillingCustomer::create(['name' => 'Source India Pvt Ltd', 'billing_state' => 'Delhi', 'is_active' => true]);

        Livewire::actingAs($this->admin)->test(\App\Livewire\Work\Clients::class)
            ->call('create')->set('name', 'Source India')->set('email', 'ops@sourceindia.test')->set('billingCustomerId', $customer->id)
            ->set('projectIds', [(string) $this->project->id])->call('save')->assertHasNoErrors();
        $this->client = Client::where('name', 'Source India')->firstOrFail();
        Livewire::actingAs($this->admin)->test(\App\Livewire\Work\Clients::class)
            ->call('openLogin', $this->client->id)->set('loginEmail', 'ops@sourceindia.test')->set('loginPassword', 'ClientPass123')->call('saveLogin')->assertHasNoErrors();
        $this->clientUser = User::where('email', 'ops@sourceindia.test')->firstOrFail();
        $this->project->refresh();
    }

    private function logTime(string $start, int $minutes, string $title = 'Category & Subcategory update'): TaskTimeEntry
    {
        $task = Task::create(['title' => $title, 'assignee_id' => $this->dev->id, 'project_id' => $this->project->id, 'status' => 'in_progress', 'priority' => 'normal']);
        $from = Carbon::parse($start);

        return TaskTimeEntry::create(['task_id' => $task->id, 'employee_id' => $this->dev->id, 'started_at' => $from, 'ended_at' => $from->copy()->addMinutes($minutes), 'duration_seconds' => $minutes * 60, 'status' => 'stopped']);
    }

    public function test_client_logins_only_reach_the_portal_and_staff_cannot_open_it(): void
    {
        $this->assertSame([$this->client->id, $this->client->id], [$this->clientUser->client_id, $this->project->client_id]);
        $this->assertFalse($this->clientUser->getRoleNames()->isNotEmpty(), 'No HRMS role');
        $this->actingAs($this->clientUser)->get(route('dashboard'))->assertRedirect(route('client.dashboard'));
        $this->get(route('work.tasks'))->assertRedirect(route('client.dashboard'));
        $this->get(route('people.employees'))->assertRedirect(route('client.dashboard'));
        $this->get(route('client.dashboard'))->assertOk()->assertSee('Source India');
        $this->actingAs($this->admin)->get(route('client.dashboard'))->assertForbidden();
        $this->postJson('/api/auth/token', ['email' => 'ops@sourceindia.test', 'password' => 'ClientPass123', 'device_name' => 'x'])->assertStatus(422);
    }

    public function test_timer_time_becomes_timesheet_lines_the_client_reviews_objects_and_approves_then_it_is_invoiced(): void
    {
        $this->logTime('2026-10-05 10:30', 90);
        $this->logTime('2026-10-06 11:00', 45, 'Meeting');
        Livewire::actingAs($this->admin)->test(\App\Livewire\Work\Timesheets::class, ['project' => $this->project->id, 'month' => '2026-10'])->assertSee('Category &amp; Subcategory update', false)
            ->assertSee(['Previous (carried in)', 'This month worked', 'This month billable', 'Total billable', 'Client review', 'Not approved yet']);
        $this->assertSame([2, 135], [TimesheetEntry::count(), (int) TimesheetEntry::sum('billable_minutes')]);
        $meeting = TimesheetEntry::where('description', 'Meeting')->firstOrFail();

        // The client sees the month and objects to the meeting.
        Livewire::actingAs($this->clientUser)->test(\App\Livewire\Client\Timesheet::class, ['project' => $this->project->id, 'month' => '2026-10'])
            ->assertSee('Meeting')->assertSee('Khushboo Verma')
            ->call('openObjection', $meeting->id)->set('reason', 'The meeting took 20 minutes, not 45.')->set('proposed', '20')->call('object')->assertHasNoErrors()
            ->call('approve')->assertHasErrors('month');
        $this->assertTrue($this->admin->notifications()->get()->contains(fn ($n) => ($n->data['kind'] ?? '') === 'TimesheetObjection'));

        // The manager accepts; the client is told and can approve.
        $objection = \App\Models\TimesheetObjection::firstOrFail();
        Livewire::actingAs($this->admin)->test(\App\Livewire\Work\Timesheets::class, ['project' => $this->project->id, 'month' => '2026-10'])
            ->call('openAnswer', $objection->id)->assertSet('answerMinutes', '20')->set('answer', 'Agreed, corrected to 20 minutes.')->call('respond', true)->assertHasNoErrors();
        $this->assertSame([20, 'accepted'], [$meeting->fresh()->billable_minutes, $objection->fresh()->status]);
        $this->assertStringContainsString('accepted your objection', $this->clientUser->notifications()->latest()->first()->data['message']);
        Livewire::actingAs($this->clientUser)->test(\App\Livewire\Client\Timesheet::class, ['project' => $this->project->id, 'month' => '2026-10'])
            ->assertSee('Agreed, corrected to 20 minutes.')->call('approve')->assertHasNoErrors()->assertSee('Approved by');
        Livewire::actingAs($this->admin)->test(\App\Livewire\Work\Timesheets::class, ['project' => $this->project->id, 'month' => '2026-10'])->assertSee('Approved by client');

        // Approved lines are locked; the month becomes an invoice at ₹600/h for 110 minutes.
        Livewire::actingAs($this->admin)->test(\App\Livewire\Work\Timesheets::class, ['project' => $this->project->id, 'month' => '2026-10'])->call('setBillable', $meeting->id, '5');
        $this->assertSame(20, $meeting->fresh()->billable_minutes);
        $invoice = app(TimesheetService::class)->createInvoice($this->project, '2026-10', $this->admin);
        $invoice->load('items.tax');
        $this->assertEquals([1.83, 600.0, $this->project->id, '998314'], [(float) $invoice->items[0]->quantity, (float) $invoice->items[0]->price, $invoice->project_id, $invoice->items[0]->hsn_sac]);
        $this->assertSame($invoice->id, \App\Models\TimesheetMonth::where('month', '2026-10')->value('invoice_id'));

        // Once sent, the client sees and prints it; others cannot.
        $invoice->update(['status' => 'Sent']);
        $this->actingAs($this->clientUser)->get(route('client.invoices'))->assertOk()->assertSee($invoice->number());
        $this->get(route('client.invoices.print', $invoice))->assertOk()->assertSee('Tax Invoice');
    }

    public function test_clients_see_only_visible_lines_of_their_own_projects(): void
    {
        $other = Project::create(['name' => 'Other client project', 'status' => 'active']);
        $hidden = TimesheetEntry::create(['project_id' => $this->project->id, 'work_date' => '2026-10-07', 'minutes' => 30, 'billable_minutes' => 30, 'description' => 'Internal rework', 'visible_to_client' => false]);
        $foreign = TimesheetEntry::create(['project_id' => $other->id, 'work_date' => '2026-10-07', 'minutes' => 30, 'billable_minutes' => 30, 'description' => 'Secret work']);
        Livewire::actingAs($this->clientUser)->test(\App\Livewire\Client\Timesheet::class, ['project' => $other->id, 'month' => '2026-10'])
            ->assertSet('project', $this->project->id)->assertDontSee('Internal rework')->assertDontSee('Secret work')
            ->call('openObjection', $foreign->id)->assertForbidden();
        Livewire::actingAs($this->clientUser)->test(\App\Livewire\Client\Timesheet::class)->call('openObjection', $hidden->id)->assertForbidden();
    }

    public function test_employees_fill_their_own_timesheet_only_on_their_projects(): void
    {
        $devUser = $this->dev->user;
        $other = Project::create(['name' => 'Not my project', 'status' => 'active']);
        $this->project->members()->attach($this->dev->id);
        $colleague = AttendanceEmployee::create(['employee_code' => 'EMP-03', 'name' => 'Neeraj', 'is_active' => true, 'joined_at' => '2026-01-01']);
        $theirs = TimesheetEntry::create(['project_id' => $this->project->id, 'employee_id' => $colleague->id, 'work_date' => '2026-10-07', 'minutes' => 30, 'billable_minutes' => 30, 'description' => 'Colleague work', 'source' => 'manual']);

        $this->actingAs($devUser)->get(route('work.my-timesheet'))->assertOk()->assertSee('My Timesheet')->assertSee('Source India')->assertDontSee('Not my project')->assertDontSee('Colleague work');
        $this->get(route('work.timesheets'))->assertForbidden();
        $this->get(route('work.timesheets.export', ['project' => $this->project->id]))->assertForbidden();

        $page = fn () => Livewire::withQueryParams(['project' => $this->project->id, 'month' => '2026-10'])->actingAs($devUser)->test(\App\Livewire\Work\Timesheets::class, ['mine' => true]);

        // Adds a line: always their own, billable = worked, shown to the client; no future dates, no off days.
        $page()->call('openEntry')->set('entry.work_date', '2026-10-31')->set('entry.employee_id', (string) $colleague->id)
            ->set('entry.description', 'Search API fix')->set('entry.start_time', '10:00')->set('entry.end_time', '11:30')->assertSet('entry.minutes', '90')
            ->set('entry.billable_minutes', '500')->call('saveEntry')->assertHasErrors('entry.work_date'); // 31 Oct 2026 is after "today" (30 Oct)
        $page()->call('openEntry')->assertSee('Stage')->assertSet('entry.status', 'Done')->assertSet('entry.stage', '')
            ->set('entry.work_date', '2026-10-29')->set('entry.employee_id', (string) $colleague->id)
            ->set('entry.description', 'Search API fix')->set('entry.start_time', '10:00')->set('entry.end_time', '11:30')
            ->set('entry.status', 'In progress')->set('entry.stage', 'React')->set('entry.billable_minutes', '500')->call('saveEntry')->assertHasNoErrors()->assertSee('Search API fix')->assertDontSee('Colleague work');
        $mineLine = TimesheetEntry::where('description', 'Search API fix')->firstOrFail();
        $this->assertSame([$this->dev->id, null, 90, 90, true, 'manual', 'In progress', 'React'], [$mineLine->employee_id, $mineLine->developer_name, $mineLine->minutes, $mineLine->billable_minutes, $mineLine->visible_to_client, $mineLine->source, $mineLine->status, $mineLine->stage]);

        // Cannot touch a colleague's line or the manager tools.
        $page()->call('openEntry', $theirs->id)->assertForbidden();
        $page()->call('deleteEntry', $theirs->id)->assertForbidden();
        $page()->call('setBillable', $mineLine->id, '5')->assertForbidden();
        $page()->call('openSettings')->assertForbidden();
        $this->assertSame([30, 90], [$theirs->fresh()->minutes, $mineLine->fresh()->billable_minutes]);

        // A project they don't work on is not reachable.
        Livewire::withQueryParams(['project' => $other->id, 'month' => '2026-10'])->actingAs($devUser)->test(\App\Livewire\Work\Timesheets::class, ['mine' => true])
            ->assertSet('project', $this->project->id);

        // Edits and removes their own line.
        $page()->call('openEntry', $mineLine->id)->set('entry.end_time', '12:00')->call('saveEntry')->assertHasNoErrors();
        $this->assertSame([120, 120], [$mineLine->fresh()->minutes, $mineLine->fresh()->billable_minutes]);
        $page()->call('deleteEntry', $mineLine->id);
        $this->assertNull($mineLine->fresh());
    }

    public function test_managers_see_their_teams_timesheet_with_the_team_name(): void
    {
        $managerUser = User::factory()->create(['email' => 'lead@example.com']);
        $managerUser->assignRole('Employee');
        $manager = AttendanceEmployee::create(['employee_code' => 'EMP-10', 'name' => 'Team Lead', 'is_active' => true, 'user_id' => $managerUser->id, 'joined_at' => '2026-01-01']);
        $this->dev->update(['manager_id' => $manager->id]);
        $riya = AttendanceEmployee::create(['employee_code' => 'EMP-11', 'name' => 'Riya Shah', 'is_active' => true, 'joined_at' => '2026-01-01']);
        $outsider = AttendanceEmployee::create(['employee_code' => 'EMP-12', 'name' => 'Outsider', 'is_active' => true, 'joined_at' => '2026-01-01']);
        $team = \App\Models\Team::create(['name' => 'React Squad', 'manager_employee_id' => $manager->id, 'is_active' => true]);
        $team->members()->attach([$this->dev->id, $riya->id]);
        $this->project->members()->attach($this->dev->id);
        $devLine = TimesheetEntry::create(['project_id' => $this->project->id, 'employee_id' => $this->dev->id, 'work_date' => '2026-10-07', 'minutes' => 60, 'billable_minutes' => 60, 'description' => 'Dev work', 'source' => 'manual']);
        TimesheetEntry::create(['project_id' => $this->project->id, 'developer_name' => 'Riya', 'work_date' => '2026-10-08', 'minutes' => 45, 'billable_minutes' => 45, 'description' => 'Typed name work', 'source' => 'import']);
        TimesheetEntry::create(['project_id' => $this->project->id, 'employee_id' => $outsider->id, 'work_date' => '2026-10-09', 'minutes' => 30, 'billable_minutes' => 30, 'description' => 'Outsider work', 'source' => 'manual']);

        $page = fn (array $q = []) => Livewire::withQueryParams($q + ['month' => '2026-10'])->actingAs($managerUser)->test(\App\Livewire\Work\Timesheets::class, ['mine' => true]);
        $page()->assertSee('My team (2)')->assertDontSee('Dev work');
        $page(['scope' => 'team'])->assertSet('project', $this->project->id)->assertSee('Dev work')->assertSee('Typed name work')->assertSee('Team: React Squad')->assertDontSee('Outsider work');
        $page(['scope' => 'team', 'member' => $riya->id])->assertSee('Typed name work')->assertDontSee('Dev work');
        // The team view is read-only: a team member's line cannot be changed from here.
        $page(['scope' => 'team', 'project' => $this->project->id])->call('openEntry', $devLine->id)->assertForbidden();

        // Someone without a team stays on their own lines.
        Livewire::withQueryParams(['scope' => 'team', 'month' => '2026-10'])->actingAs($this->dev->user)->test(\App\Livewire\Work\Timesheets::class, ['mine' => true])
            ->assertSet('scope', 'mine')->assertDontSee('Typed name work');

        \Laravel\Sanctum\Sanctum::actingAs($managerUser, ['*']);
        $api = $this->getJson('/api/timesheet?month=2026-10&scope=team')->assertOk()->assertJsonPath('scope', 'team')->assertJsonCount(2, 'team');
        $this->assertEqualsCanonicalizing(['Dev work', 'Typed name work'], collect($api->json('data'))->pluck('description')->all());
        $this->assertSame([false, 'React Squad'], [collect($api->json('data'))->firstWhere('description', 'Dev work')['editable'], collect($api->json('data'))->firstWhere('description', 'Dev work')['team']]);
        $this->getJson('/api/timesheet?month=2026-10')->assertJsonPath('scope', 'mine')->assertJsonCount(0, 'data');
    }

    public function test_the_app_lets_employees_fill_their_own_timesheet(): void
    {
        $this->project->members()->attach($this->dev->id);
        Project::create(['name' => 'Not my project', 'status' => 'active']);
        \Laravel\Sanctum\Sanctum::actingAs($this->dev->user, ['*']);

        $list = $this->getJson('/api/timesheet?month=2026-10')->assertOk();
        $this->assertSame(['Source India'], collect($list->json('projects'))->pluck('name')->all());

        $this->postJson('/api/timesheet', ['project_id' => $this->project->id, 'work_date' => '2026-10-31', 'start_time' => '10:00', 'end_time' => '11:00', 'description' => 'Future work'])
            ->assertStatus(422)->assertJsonValidationErrors('work_date');
        $created = $this->postJson('/api/timesheet', ['project_id' => $this->project->id, 'work_date' => '2026-10-29', 'start_time' => '22:30', 'end_time' => '00:15', 'description' => 'Night deploy', 'status' => 'Done', 'stage' => 'Live'])
            ->assertCreated()->assertJsonPath('data.minutes', 105)->assertJsonPath('data.editable', true)->assertJsonPath('data.status', 'Done')->assertJsonPath('data.stage', 'Live');
        $id = $created->json('data.id');
        $this->assertSame([$this->dev->id, 105, true], [TimesheetEntry::find($id)->employee_id, TimesheetEntry::find($id)->billable_minutes, TimesheetEntry::find($id)->visible_to_client]);

        $this->postJson("/api/timesheet/{$id}", ['project_id' => $this->project->id, 'work_date' => '2026-10-29', 'start_time' => '22:30', 'end_time' => '23:30', 'description' => 'Night deploy'])
            ->assertOk()->assertJsonPath('data.minutes', 60);
        $this->getJson('/api/timesheet?month=2026-10')->assertJsonPath('total_minutes', 60)->assertJsonCount(1, 'data');

        $theirs = TimesheetEntry::create(['project_id' => $this->project->id, 'work_date' => '2026-10-07', 'minutes' => 30, 'billable_minutes' => 30, 'description' => 'Someone else', 'source' => 'manual']);
        $this->postJson("/api/timesheet/{$theirs->id}/delete")->assertNotFound();
        $this->postJson("/api/timesheet/{$id}/delete")->assertOk();
        $this->assertNull(TimesheetEntry::find($id));
    }

    public function test_excel_export_follows_the_client_sheet_layout_with_totals(): void
    {
        $this->project->update(['hourly_rate' => 600]);
        app(TimesheetService::class)->month($this->project, '2026-10')->update(['opening_remaining_minutes' => 30]);
        TimesheetEntry::create(['project_id' => $this->project->id, 'employee_id' => $this->dev->id, 'developer_name' => 'Abheeshri Parmar', 'work_date' => '2026-10-07', 'start_time' => '15:30', 'end_time' => '17:18', 'minutes' => 108, 'billable_minutes' => 90, 'description' => 'PPT on platform overview', 'status' => 'Done']);

        $response = $this->actingAs($this->admin)->get(route('work.timesheets.export', ['project' => $this->project->id, 'month' => '2026-10']))->assertOk();
        $sheet = \App\Support\XlsxReader::rows($response->getFile()->getPathname());
        $flat = json_encode($sheet, JSON_UNESCAPED_UNICODE);
        foreach (['Task Details', 'In Minutes', 'Old remaining (carried in)', 'PPT on platform overview', '1 h 48 min', 'Abheeshri Parmar', 'Total', 'Amount'] as $text) {
            $this->assertStringContainsString($text, $flat, $text);
        }
    }

    public function test_old_timesheet_workbook_imports_like_the_source_india_sheet(): void
    {
        $sheet = fn (string $name, array $rows) => ['name' => $name, 'columns' => array_map(fn ($h) => [$h, 'text', 12], ['Date', 'Task Details', 'Start Time', 'End Time', 'Hours', 'In Minutes', '', 'Developer', 'Status', 'Remarks']), 'rows' => $rows];
        $path = \App\Support\XlsxWorkbook::toTempFile([
            $sheet('Oct 2026', [
                ['', 'Old remaining', '', '', '', 215, 215, '', '', ''],
                [46302, 'Category & Subcategory Relationship Update', 0.4375, 0.0833333, '', 210, 100, 'Khushboo', 'Done', ''],
                [46303, 'Meeting', '', '', '1.5', 90, '', 'Neeraj', 'Live', ''],
            ]),
            ['name' => 'Sheet3', 'columns' => [['Start Time', 'text', 10]], 'rows' => [[0.41]]],
        ]);
        $file = fn () => new \Illuminate\Http\UploadedFile($path, 'sourceindia_sheet.xlsx', null, null, true);
        $result = app(TimesheetService::class)->import($this->project, $file(), $this->admin->id);

        $this->assertSame([2, ['2026-10']], [$result['created'], $result['months']], json_encode($result));
        $line = TimesheetEntry::where('description', 'like', 'Category%')->firstOrFail();
        $this->assertSame(['2026-10-07', '10:30', '14:00', 210, 100, $this->dev->id], [$line->work_date->toDateString(), substr($line->start_time, 0, 5), substr($line->end_time, 0, 5), $line->minutes, $line->billable_minutes, $line->employee_id]);
        $this->assertSame([90, 'Neeraj', null], [TimesheetEntry::where('description', 'Meeting')->value('billable_minutes'), TimesheetEntry::where('description', 'Meeting')->value('developer_name'), TimesheetEntry::where('description', 'Meeting')->value('employee_id')]);
        $this->assertSame([null, 'Live', 'Done', null], [TimesheetEntry::where('description', 'Meeting')->value('status'), TimesheetEntry::where('description', 'Meeting')->value('stage'), $line->status, $line->stage]);
        $this->assertSame(215, \App\Models\TimesheetMonth::where('month', '2026-10')->value('opening_remaining_minutes'));
        $this->assertSame(215 + 100 + 90, app(TimesheetService::class)->summary($this->project, '2026-10')['billable_minutes']);

        $again = app(TimesheetService::class)->import($this->project, $file(), $this->admin->id);
        $this->assertSame([0, 2], [$again['created'], $again['kept']]);
    }
}
