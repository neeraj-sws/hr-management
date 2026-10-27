<?php

namespace App\Http\Controllers\Api;

use App\Models\EmployeeNote;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notes in the mobile and desktop apps: the person's own notes (add, edit, delete) and, read only,
 * notes their manager shared with them and notes shared with their teams. Same rules as the website.
 */
class NoteController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->apiUser($request);
        $own = $user->employeeProfile;
        $teamIds = $own ? $own->teams()->pluck('teams.id')->all() : [];
        $term = trim((string) $request->query('q', ''));

        $notes = EmployeeNote::with(['author', 'employee', 'team'])
            ->where(fn ($q) => $q
                ->where('author_id', $user->id)
                ->orWhere(fn ($q) => $q->where('visibility', 'manager')->where('employee_id', $own?->id ?? 0))
                ->orWhere(fn ($q) => $q->where('visibility', 'team')->whereIn('team_id', $teamIds ?: [0])))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('title', 'like', "%{$term}%")->orWhere('body', 'like', "%{$term}%")))
            ->latest('updated_at')->limit(200)->get();

        return response()->json(['data' => $notes->map(fn ($n) => $this->present($n, $user))->values()]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $this->apiUser($request);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'body' => ['required', 'string', 'max:10000']]);
        // From the apps a note is private (only the person sees it); sharing stays on the website.
        $note = EmployeeNote::create([...$data, 'visibility' => 'personal', 'author_id' => $user->id]);
        $audit->record('note.created', $note, ['visibility' => 'personal', 'channel' => 'api']);

        return response()->json(['message' => 'Note saved.', 'data' => $this->present($note->load('author'), $user)], 201);
    }

    public function update(Request $request, EmployeeNote $note, AuditLogger $audit): JsonResponse
    {
        $user = $this->apiUser($request);
        abort_unless($user->can('update', $note), 403, 'Only the person who wrote this note can change it.');
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'body' => ['required', 'string', 'max:10000']]);
        $note->update($data);
        $audit->record('note.updated', $note, ['visibility' => $note->visibility, 'channel' => 'api']);

        return response()->json(['message' => 'Note saved.', 'data' => $this->present($note->fresh(['author', 'employee', 'team']), $user)]);
    }

    public function destroy(Request $request, EmployeeNote $note, AuditLogger $audit): JsonResponse
    {
        $user = $this->apiUser($request);
        abort_unless($user->can('delete', $note), 403, 'Only the person who wrote this note can delete it.');
        $audit->record('note.deleted', $note, ['channel' => 'api']);
        $note->delete();

        return response()->json(['message' => 'Note deleted.']);
    }

    private function present(EmployeeNote $note, $user): array
    {
        $mine = $note->author_id === $user->id;

        return [
            'id' => $note->id,
            'title' => $note->title,
            'body' => $note->body,
            'updated_at' => $note->updated_at?->toIso8601String(),
            'author' => $note->author?->name,
            'mine' => $mine,
            'can_edit' => $user->can('update', $note),
            'shared' => match ($note->visibility) {
                'manager' => $mine ? 'About '.($note->employee?->name ?? 'a team member') : 'From '.($note->author?->name ?? 'your manager'),
                'team' => 'Team: '.($note->team?->name ?? '—'),
                default => 'Private',
            },
        ];
    }
}
