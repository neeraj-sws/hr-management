<?php

namespace Tests\Feature;

use App\Livewire\Work\Tasks;
use App\Models\AttendanceEmployee;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Documents (Excel, Word, PDF …) attached to a task. */
class TaskDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_are_attached_downloaded_by_people_who_see_the_task_and_removed(): void
    {
        $this->seed();
        Storage::fake('local');
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $employee = AttendanceEmployee::create(['employee_code' => 'EMP-T1', 'name' => 'Task Doer', 'is_active' => true, 'joined_at' => '2026-01-01']);

        $excel = UploadedFile::fake()->createWithContent('plan.xlsx', 'PK-fake-xlsx');
        Livewire::actingAs($admin)->test(Tasks::class)->call('create')->set('title', 'Prepare sheet')->set('assigneeId', $employee->id)
            ->set('uploads', [$excel, UploadedFile::fake()->create('brief.pdf', 20, 'application/pdf')])->call('save')->assertHasNoErrors();
        $task = Task::where('title', 'Prepare sheet')->firstOrFail();
        $this->assertSame(['plan.xlsx', 'brief.pdf'], $task->attachments()->pluck('name')->all());

        $file = $task->attachments()->firstOrFail();
        $this->actingAs($admin)->get(route('work.task-file', $file))->assertOk();
        Livewire::actingAs($admin)->test(Tasks::class)->call('open', $task->id)->assertSee(['Documents', 'plan.xlsx']);

        // Someone who cannot see the task cannot download it.
        $stranger = User::factory()->create();
        $stranger->assignRole('Employee');
        $this->actingAs($stranger)->get(route('work.task-file', $file))->assertForbidden();

        // A file type that is not allowed is refused.
        Livewire::actingAs($admin)->test(Tasks::class)->call('create')->set('title', 'Bad file')->set('assigneeId', $employee->id)
            ->set('uploads', [UploadedFile::fake()->create('run.exe', 10)])->call('save')->assertHasErrors('uploads.0');

        // Removing deletes the file.
        Livewire::actingAs($admin)->test(Tasks::class)->call('removeAttachment', $file->id);
        $this->assertNull(TaskAttachment::find($file->id));
        Storage::disk('local')->assertMissing($file->path);
    }

    public function test_the_due_date_is_changed_from_the_list(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $employee = AttendanceEmployee::create(['employee_code' => 'EMP-T2', 'name' => 'Date Person', 'is_active' => true, 'joined_at' => '2026-01-01']);
        Livewire::actingAs($admin)->test(Tasks::class)->call('create')->set('title', 'Dated task')->set('assigneeId', $employee->id)->set('dueOn', '2026-11-30')->call('save')->assertHasNoErrors();
        $task = Task::where('title', 'Dated task')->firstOrFail();
        Livewire::actingAs($admin)->test(Tasks::class)->assertSee('Change due date')->call('updateDue', $task->id, '2026-12-05');
        $this->assertSame('2026-12-05', $task->fresh()->due_on->toDateString());
        Livewire::actingAs($admin)->test(Tasks::class)->call('updateDue', $task->id, 'not-a-date');
        $this->assertSame('2026-12-05', $task->fresh()->due_on->toDateString());
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'task.due_changed')->count());
    }

    public function test_a_task_is_put_on_its_project_timesheet(): void
    {
        $this->seed();
        \Illuminate\Support\Carbon::setTestNow('2026-10-30 12:00:00');
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $employee = AttendanceEmployee::create(['employee_code' => 'EMP-T3', 'name' => 'Timesheet Person', 'is_active' => true, 'joined_at' => '2026-01-01']);
        $project = \App\Models\Project::create(['name' => 'Source India', 'status' => 'active']);
        Livewire::actingAs($admin)->test(Tasks::class)->call('create')->set('title', 'Fix login page')->set('assigneeId', $employee->id)->set('projectId', $project->id)->set('estimatedHours', '2')->call('save')->assertHasNoErrors();
        $task = Task::where('title', 'Fix login page')->firstOrFail();

        $page = Livewire::actingAs($admin)->test(Tasks::class)->assertSee('To timesheet')->call('openToTimesheet', $task->id)->assertSet('tsMinutes', '120');
        $page->set('tsDate', '2026-10-04')->call('saveToTimesheet')->assertHasErrors('tsDate'); // a Sunday
        $page->call('openToTimesheet', $task->id)->set('tsDate', '2026-10-06')->set('tsMinutes', '90')->call('saveToTimesheet')->assertHasNoErrors();
        $line = \App\Models\TimesheetEntry::where('task_id', $task->id)->firstOrFail();
        $this->assertSame([$project->id, $employee->id, '2026-10-06', 90, 'Fix login page', false], [$line->project_id, $line->employee_id, $line->work_date->toDateString(), $line->minutes, $line->description, $line->visible_to_client]);
        Livewire::actingAs($admin)->test(Tasks::class)->call('openToTimesheet', $task->id)->assertSee('already has 01:30:00 on the timesheet');
    }
}
