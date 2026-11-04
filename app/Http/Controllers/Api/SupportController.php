<?php

namespace App\Http\Controllers\Api;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Help & Support in the mobile app (the employee's own requests): categories, list, raise a request with files,
 * the chat (never internal notes), reply, reopen. Files open through short-lived signed links.
 */
class SupportController extends ApiController
{
    public function categories(Request $request, SupportService $support): JsonResponse
    {
        $this->apiEmployee($request);

        return response()->json(['data' => $support->categories()->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'description' => $c->description])->values(),
            'statuses' => SupportService::STATUSES, 'max_files' => SupportService::MAX_FILES, 'max_file_mb' => 5, 'file_types' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf']]);
    }

    public function index(Request $request, SupportService $support): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $user = $this->apiUser($request);
        $status = (string) $request->query('status', '');
        $tickets = SupportTicket::with(['category', 'assignee'])->where('employee_id', $employee->id)
            ->when($status === 'active', fn ($q) => $q->whereIn('status', SupportService::ACTIVE))
            ->when($status === 'finished', fn ($q) => $q->whereIn('status', ['resolved', 'closed']))
            ->orderByDesc('last_message_at')->orderByDesc('id')->paginate(min(50, max(5, (int) $request->query('per_page', 20))));
        $unread = $support->unreadIds($user, $tickets->pluck('id'), false);

        return response()->json(['data' => $tickets->getCollection()->map(fn ($t) => $this->summary($t, $support, in_array($t->id, $unread, true)))->values(),
            'meta' => ['current_page' => $tickets->currentPage(), 'last_page' => $tickets->lastPage(), 'total' => $tickets->total()]]);
    }

    public function store(Request $request, SupportService $support): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $user = $this->apiUser($request);

        return $this->once($request, function () use ($request, $support, $employee, $user) {
            $ticket = $support->create($employee, $user, $request->only(['category_id', 'subject', 'description']), $this->files($request));

            return response()->json(['data' => $this->detail($ticket->refresh(), $support, $user)], 201);
        });
    }

    public function show(Request $request, SupportTicket $ticket, SupportService $support): JsonResponse
    {
        $user = $this->ownTicket($request, $ticket, $support);
        $support->markRead($ticket, $user);

        return response()->json(['data' => $this->detail($ticket, $support, $user)]);
    }

    public function reply(Request $request, SupportTicket $ticket, SupportService $support): JsonResponse
    {
        $user = $this->ownTicket($request, $ticket, $support);

        return $this->once($request, function () use ($request, $ticket, $support, $user) {
            $support->reply($ticket, $user, (string) $request->input('body', ''), $this->files($request));

            return response()->json(['data' => $this->detail($ticket->refresh(), $support, $user)], 201);
        });
    }

    public function reopen(Request $request, SupportTicket $ticket, SupportService $support): JsonResponse
    {
        $user = $this->ownTicket($request, $ticket, $support);
        $support->reopen($ticket, $user, (string) $request->input('reason', ''));

        return response()->json(['data' => $this->detail($ticket->refresh(), $support, $user)]);
    }

    /** Only the employee's own request (support staff use the website). */
    private function ownTicket(Request $request, SupportTicket $ticket, SupportService $support): User
    {
        $this->apiEmployee($request);
        $user = $this->apiUser($request);
        abort_unless($support->isOwner($user, $ticket->load(['employee', 'category', 'assignee'])), 403, 'This request is not yours.');

        return $user;
    }

    /** attachments[] (or a single "attachment"). */
    private function files(Request $request): array
    {
        $files = $request->file('attachments', []);
        $files = is_array($files) ? $files : [$files];
        if ($request->hasFile('attachment')) $files[] = $request->file('attachment');

        return array_values(array_filter($files));
    }

    private function summary(SupportTicket $t, SupportService $support, bool $unread): array
    {
        return [
            'id' => $t->id, 'number' => $t->number(), 'subject' => $t->subject, 'category' => $t->category?->name, 'category_id' => $t->category_id,
            'status' => $t->status, 'status_label' => SupportService::STATUSES[$t->status] ?? $t->status,
            'handled_by' => $t->assignee?->name, 'unread' => $unread,
            'created_at' => $t->created_at?->toIso8601String(), 'last_message_at' => ($t->last_message_at ?? $t->updated_at)?->toIso8601String(),
            'resolved_at' => $t->resolved_at?->toIso8601String(),
            'can_reopen' => $support->canReopen($t), 'reopen_until' => $support->reopenUntil($t)?->toDateString(),
        ];
    }

    private function detail(SupportTicket $ticket, SupportService $support, User $user): array
    {
        $ticket->loadMissing(['category', 'assignee']);
        $messages = $support->visibleMessages($ticket, false);

        return $this->summary($ticket, $support, false) + [
            'description' => $ticket->description,
            'can_reply' => in_array($ticket->status, SupportService::ACTIVE, true),
            'messages' => $messages->map(fn (SupportMessage $m) => $this->message($m, $ticket, $support, $user))->values(),
        ];
    }

    private function message(SupportMessage $m, SupportTicket $ticket, SupportService $support, User $user): array
    {
        $hidden = $m->hidden_at !== null;

        return [
            'id' => $m->id, 'mine' => (int) $m->user_id === (int) $user->id,
            'sender' => $m->user?->name ?? 'Former user', 'role' => $support->roleLabel($m->user, $ticket, $m->sender_type), 'sender_type' => $m->sender_type,
            'body' => $hidden ? null : $m->body, 'removed' => $hidden, 'removed_text' => $hidden ? 'This message was removed by the support admin.' : null,
            'created_at' => $m->created_at?->toIso8601String(),
            'attachments' => $hidden ? [] : $m->attachments->map(fn ($a) => [
                'id' => $a->id, 'name' => $a->original_name, 'mime_type' => $a->mime_type, 'size' => $a->size, 'is_image' => $a->isImage(),
                'url' => URL::temporarySignedRoute('files.support-attachment', now()->addMinutes(15), ['attachment' => $a->id, 'user' => $user->id]),
            ])->values(),
        ];
    }
}
