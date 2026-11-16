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
}
