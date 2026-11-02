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
        Livewire::actingAs($this->admin)->test(\App\Livewire\Work\Timesheets::class, ['project' => $this->project->id, 'month' => '2026-10'])->assertSee('Category &amp; Subcategory update', false);
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

    public function test_old_timesheet_workbook_imports_like_the_source_india_sheet(): void
    {
        $sheet = fn (string $name, array $rows) => ['name' => $name, 'columns' => array_map(fn ($h) => [$h, 'text', 12], ['Date', 'Task Details', 'Start Time', 'End Time', 'Hours', 'In Minutes', '', 'Developer', 'Status', 'Remarks']), 'rows' => $rows];
        $path = \App\Support\XlsxWorkbook::toTempFile([
            $sheet('Oct 2026', [
                ['', 'Old remaining', '', '', '', 215, 215, '', '', ''],
                [46302, 'Category & Subcategory Relationship Update', 0.4375, 0.0833333, '', 210, 100, 'Khushboo', 'Done', ''],
                [46303, 'Meeting', '', '', '1.5', 90, '', 'Neeraj', '', ''],
            ]),
            ['name' => 'Sheet3', 'columns' => [['Start Time', 'text', 10]], 'rows' => [[0.41]]],
        ]);
        $file = fn () => new \Illuminate\Http\UploadedFile($path, 'sourceindia_sheet.xlsx', null, null, true);
        $result = app(TimesheetService::class)->import($this->project, $file(), $this->admin->id);

        $this->assertSame([2, ['2026-10']], [$result['created'], $result['months']], json_encode($result));
        $line = TimesheetEntry::where('description', 'like', 'Category%')->firstOrFail();
        $this->assertSame(['2026-10-07', '10:30', '14:00', 210, 100, $this->dev->id], [$line->work_date->toDateString(), substr($line->start_time, 0, 5), substr($line->end_time, 0, 5), $line->minutes, $line->billable_minutes, $line->employee_id]);
        $this->assertSame([90, 'Neeraj', null], [TimesheetEntry::where('description', 'Meeting')->value('billable_minutes'), TimesheetEntry::where('description', 'Meeting')->value('developer_name'), TimesheetEntry::where('description', 'Meeting')->value('employee_id')]);
        $this->assertSame(215, \App\Models\TimesheetMonth::where('month', '2026-10')->value('opening_remaining_minutes'));
        $this->assertSame(215 + 100 + 90, app(TimesheetService::class)->summary($this->project, '2026-10')['billable_minutes']);

        $again = app(TimesheetService::class)->import($this->project, $file(), $this->admin->id);
        $this->assertSame([0, 2], [$again['created'], $again['kept']]);
    }
}
