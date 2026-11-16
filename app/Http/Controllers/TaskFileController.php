<?php

namespace App\Http\Controllers;

use App\Models\TaskAttachment;
use App\Services\WorkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Download of a task's document — only for people who can see the task. */
class TaskFileController extends Controller
{
    public function download(Request $request, TaskAttachment $attachment, WorkService $work)
    {
        $task = $attachment->task()->with('assignee')->firstOrFail();
        abort_unless($work->canSeeTask($request->user(), $task), 403);
        abort_unless(str_starts_with($attachment->path, 'tasks/') && Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
