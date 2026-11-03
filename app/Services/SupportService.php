<?php

namespace App\Services;

use App\Models\AttendanceEmployee;
use App\Models\PolicyRule;
use App\Models\SupportAttachment;
use App\Models\SupportCategory;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Models\SupportTicketRead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Help & Support: employees raise a request (ticket) and chat with the support staff about it.
 *
 * Who sees what (all by permission, never by user id):
 *   - the employee: only their own requests, never internal notes;
 *   - support.manage: every request, assigns anyone, categories, settings and reports;
 *   - support.handle: requests assigned to them and requests nobody has taken yet;
 *   - restricted categories (Salary & Payroll): only people who also hold support.payroll.
 * Every status, priority, category and assignment change goes into the ticket's history (and the audit log).
 * Nothing is resolved or closed automatically.
 */
class SupportService
{
    public const STATUSES = [
        'open' => 'Open', 'assigned' => 'Assigned', 'in_progress' => 'In progress',
        'waiting_for_employee' => 'Waiting for employee', 'resolved' => 'Resolved', 'closed' => 'Closed',
    ];
    public const STATUS_TONES = ['open' => 'blue', 'assigned' => 'violet', 'in_progress' => 'amber', 'waiting_for_employee' => 'navy', 'resolved' => 'green', 'closed' => 'gray'];
    /** Not resolved or closed yet. */
    public const ACTIVE = ['open', 'assigned', 'in_progress', 'waiting_for_employee'];

    public const PRIORITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'];
    public const PRIORITY_TONES = ['low' => 'gray', 'medium' => 'blue', 'high' => 'amber', 'urgent' => 'red'];

    /** Images / screenshots and PDFs, up to 5 MB each, up to 5 with one message. */
    public const FILE_RULES = ['file', 'max:5120', 'mimes:jpg,jpeg,png,webp,gif,pdf'];
    public const MAX_FILES = 5;

    public const SETTINGS_KEY = 'support.settings';
    public const DEFAULT_REOPEN_DAYS = 7;

    public const KIND = 'SupportTicket';

    public function __construct(private readonly AuditLogger $audit) {}

    /* ------------------------------------------------------------------ who may do what */

    public static function isStaff(?User $user): bool
    {
        return $user !== null && $user->canAny(['support.manage', 'support.handle']);
    }

    public static function canManage(?User $user): bool
    {
        return $user !== null && $user->can('support.manage');
    }

    /** The tickets a support person may see (the support desk, reports). */
    public function staffScope(Builder $query, User $user): Builder
    {
        if (! self::isStaff($user)) return $query->whereRaw('1 = 0');
        if (! $user->can('support.payroll')) $query->whereIn('category_id', SupportCategory::where('is_restricted', false)->select('id'));
        if (! $user->can('support.manage')) $query->where(fn ($q) => $q->where('assigned_to', $user->id)->orWhereNull('assigned_to'));

        return $query;
    }

    public function staffCanView(?User $user, SupportTicket $ticket): bool
    {
        if (! self::isStaff($user)) return false;
        if ($ticket->category?->is_restricted && ! $user->can('support.payroll')) return false;

        return $user->can('support.manage') || $ticket->assigned_to === null || (int) $ticket->assigned_to === (int) $user->id;
    }

    public function isOwner(?User $user, SupportTicket $ticket): bool
    {
        return $user !== null && $ticket->employee !== null && (int) $ticket->employee->user_id === (int) $user->id;
    }

    public function canView(?User $user, SupportTicket $ticket): bool
    {
        return $this->isOwner($user, $ticket) || $this->staffCanView($user, $ticket);
    }

    /** Reply, change the status / priority: an admin, or the handler it is assigned to (or nobody has it yet). */
    public function canHandle(?User $user, SupportTicket $ticket): bool
    {
        return $this->staffCanView($user, $ticket);
    }

    /** People a ticket can be assigned to: active users with a support permission (and support.payroll for restricted ones). */
    public function handlers(?SupportTicket $ticket = null): Collection
    {
        $restricted = (bool) $ticket?->category?->is_restricted;

        return Notifier::withPermission('support.manage', 'support.handle')
            ->filter(fn (User $u) => ! $restricted || $u->can('support.payroll'))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /** Support staff who should hear about a ticket nobody has taken (or the assignee). */
    private function staffToTell(SupportTicket $ticket): Collection
    {
        if ($ticket->assigned_to) return User::where('id', $ticket->assigned_to)->where('is_active', true)->get();

        return $this->handlers($ticket);
    }

    /* ------------------------------------------------------------------ settings */

    public function categories(bool $activeOnly = true): Collection
    {
        return SupportCategory::query()->when($activeOnly, fn ($q) => $q->where('is_active', true))->orderBy('sort_order')->orderBy('name')->get();
    }

    /** Days after "Resolved" in which the employee may reopen the request (0 = they cannot reopen). */
    public function reopenDays(): int
    {
        $config = PolicyRule::where('rule_key', self::SETTINGS_KEY)->value('configuration');
        $config = is_array($config) ? $config : (json_decode((string) $config, true) ?: []);

        return max(0, min(365, (int) ($config['reopen_days'] ?? self::DEFAULT_REOPEN_DAYS)));
    }

    public function setReopenDays(int $days, User $actor): void
    {
        abort_unless(self::canManage($actor), 403);
        $days = max(0, min(365, $days));
        $before = $this->reopenDays();
        $rule = PolicyRule::firstOrNew(['rule_key' => self::SETTINGS_KEY]);
        $rule->fill(['category' => 'support', 'name' => 'Help & Support settings', 'configuration' => ['reopen_days' => $days], 'is_active' => true, 'updated_by' => $actor->id])->save();
        $this->audit->record('support.settings_updated', $rule, ['reopen_days' => ['from' => $before, 'to' => $days]]);
    }

    public function saveCategory(?SupportCategory $category, array $data, User $actor): SupportCategory
    {
        abort_unless(self::canManage($actor), 403);
        $data = validator($data, [
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_restricted' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ])->validate();
        $exists = SupportCategory::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])->when($category, fn ($q) => $q->whereKeyNot($category->id))->exists();
        if ($exists) throw ValidationException::withMessages(['name' => 'A category with this name already exists.']);

        $category ??= new SupportCategory(['slug' => $this->uniqueSlug($data['name'])]);
        $before = $category->exists ? $category->only(['name', 'description', 'is_restricted', 'is_active', 'sort_order']) : null;
        $category->fill([
            'name' => trim($data['name']), 'description' => $data['description'] ?? null,
            'is_restricted' => (bool) ($data['is_restricted'] ?? false), 'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? ($category->sort_order ?: (int) SupportCategory::max('sort_order') + 10)),
        ])->save();
        $this->audit->record($before ? 'support.category_updated' : 'support.category_created', $category, ['before' => $before, 'after' => $category->only(['name', 'description', 'is_restricted', 'is_active', 'sort_order'])]);

        return $category;
    }

    private function uniqueSlug(string $name): string
    {
        $base = \Illuminate\Support\Str::slug($name) ?: 'category';
        $base = substr($base, 0, 34);
        $slug = $base;
        for ($i = 2; SupportCategory::where('slug', $slug)->exists(); $i++) $slug = $base.'-'.$i;

        return $slug;
    }

    /* ------------------------------------------------------------------ employee actions */

    /**
     * @param  array{category_id: mixed, subject: mixed, description: mixed}  $data
     * @param  list<UploadedFile>  $files
     */
    public function create(AttendanceEmployee $employee, User $actor, array $data, array $files = []): SupportTicket
    {
        abort_unless((int) $employee->user_id === (int) $actor->id, 403);
        $data = validator(['category_id' => $data['category_id'] ?? null, 'subject' => $data['subject'] ?? null, 'description' => $data['description'] ?? null, 'attachments' => $files], [
            'category_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('support_categories', 'id')->where('is_active', true)],
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'description' => ['required', 'string', 'min:5', 'max:5000'],
            'attachments' => ['array', 'max:'.self::MAX_FILES],
            'attachments.*' => self::FILE_RULES,
        ], ['category_id.required' => 'Choose a category.', 'attachments.*.mimes' => 'Attach images, screenshots or PDF files only.', 'attachments.*.max' => 'Each file can be up to 5 MB.'],
            ['category_id' => 'category', 'attachments.*' => 'file'])->validate();

        $ticket = DB::transaction(function () use ($employee, $actor, $data, $files) {
            $now = now();
            $ticket = SupportTicket::create([
                'employee_id' => $employee->id, 'created_by' => $actor->id, 'category_id' => (int) $data['category_id'],
                'subject' => trim($data['subject']), 'description' => trim($data['description']),
                'priority' => 'medium', 'status' => 'open', 'last_message_at' => $now, 'last_message_by' => $actor->id,
            ]);
            $message = $ticket->messages()->create(['user_id' => $actor->id, 'sender_type' => 'employee', 'body' => trim($data['description'])]);
            $this->storeFiles($ticket, $message, $files, $actor);
            $this->event($ticket, $actor, 'created', null, 'open');
            $this->markRead($ticket, $actor);

            return $ticket;
        });
        $ticket->load(['employee', 'category']);
        $this->tell($this->staffToTell($ticket), $ticket, $employee->name.' raised a support request '.$ticket->number().' ('.$ticket->category->name.'): '.$ticket->subject, $actor->id);

        return $ticket;
    }

    /** Opens a resolved request again (the employee, within the reopen days). */
    public function reopen(SupportTicket $ticket, User $actor, string $reason): SupportTicket
    {
        abort_unless($this->isOwner($actor, $ticket), 403);
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:2000']], [], ['reason' => 'reason'])->validate();
        if (! $this->canReopen($ticket)) {
            throw ValidationException::withMessages(['reason' => $ticket->status === 'resolved' ? 'This request can no longer be reopened. Please raise a new request.' : 'Only a resolved request can be reopened.']);
        }

        DB::transaction(function () use ($ticket, $actor, $reason) {
            $to = $ticket->assigned_to ? 'assigned' : 'open';
            $message = $ticket->messages()->create(['user_id' => $actor->id, 'sender_type' => 'employee', 'body' => trim($reason)]);
            $this->event($ticket, $actor, 'reopened', $ticket->status, $to, trim($reason));
            $ticket->update(['status' => $to, 'resolved_at' => null, 'resolved_by' => null, 'reopen_count' => $ticket->reopen_count + 1, 'last_message_at' => $message->created_at, 'last_message_by' => $actor->id]);
            $this->markRead($ticket, $actor);
        });
        $this->tell($this->staffToTell($ticket), $ticket, ($ticket->employee?->name ?? 'The employee').' reopened support request '.$ticket->number().': '.$reason, $actor->id);

        return $ticket->refresh();
    }

    public function canReopen(SupportTicket $ticket): bool
    {
        if ($ticket->status !== 'resolved' || ! $ticket->resolved_at) return false;
        $days = $this->reopenDays();

        return $days > 0 && $ticket->resolved_at->copy()->addDays($days)->isFuture();
    }

    /** Last day the employee may reopen (null when they cannot). */
    public function reopenUntil(SupportTicket $ticket): ?Carbon
    {
        return $this->canReopen($ticket) ? $ticket->resolved_at->copy()->addDays($this->reopenDays()) : null;
    }

    /* ------------------------------------------------------------------ chat */

    /**
     * A message from the employee (own request) or from support staff (public reply or internal note);
     * staff can set a new status with the same message (one notification for both).
     *
     * @param  list<UploadedFile>  $files
     */
    public function reply(SupportTicket $ticket, User $actor, ?string $body, array $files = [], bool $internal = false, ?string $status = null): SupportMessage
    {
        $staff = $this->staffCanView($actor, $ticket);
        abort_unless($staff || $this->isOwner($actor, $ticket), 403);
        if (! $staff && ($internal || $status !== null)) abort(403);
        $body = trim((string) $body);
        validator(['body' => $body, 'attachments' => $files, 'status' => $status], [
            'body' => [$files ? 'nullable' : 'required', 'string', 'max:5000'],
            'attachments' => ['array', 'max:'.self::MAX_FILES],
            'attachments.*' => self::FILE_RULES,
            'status' => ['nullable', 'in:'.implode(',', array_keys(self::STATUSES))],
        ], ['body.required' => 'Write a message or attach a file.', 'attachments.*.mimes' => 'Attach images, screenshots or PDF files only.', 'attachments.*.max' => 'Each file can be up to 5 MB.'],
            ['body' => 'message', 'attachments.*' => 'file'])->validate();

        if ($ticket->status === 'closed' && ! $internal) throw ValidationException::withMessages(['body' => 'This request is closed. '.($staff ? 'Change its status first, or add an internal note.' : 'Please raise a new request.')]);
        if (! $staff && $ticket->status === 'resolved') throw ValidationException::withMessages(['body' => $this->canReopen($ticket) ? 'This request is resolved. Use "Reopen" if your problem is not solved.' : 'This request is resolved. Please raise a new request.']);

        $statusChanged = false;
        $message = DB::transaction(function () use ($ticket, $actor, $body, $files, $internal, $status, $staff, &$statusChanged) {
            $message = $ticket->messages()->create(['user_id' => $actor->id, 'sender_type' => $staff && ! $this->isOwner($actor, $ticket) ? 'staff' : 'employee', 'body' => $body, 'is_internal' => $internal]);
            $this->storeFiles($ticket, $message, $files, $actor);
            if (! $internal) {
                $updates = ['last_message_at' => $message->created_at, 'last_message_by' => $actor->id];
                if ($message->sender_type === 'staff' && ! $ticket->first_response_at) $updates['first_response_at'] = $message->created_at;
                $ticket->update($updates);
                // The employee answered: the ball is back with support.
                if ($message->sender_type === 'employee' && $ticket->status === 'waiting_for_employee') $statusChanged = $this->changeStatus($ticket, $actor, 'in_progress', 'Employee replied');
            }
            if ($status !== null) $statusChanged = $this->changeStatus($ticket, $actor, $status) || $statusChanged;
            $this->markRead($ticket, $actor);

            return $message;
        });

        $ticket->refresh()->load(['employee', 'category']);
        $number = $ticket->number();
        $statusText = $statusChanged ? ' Status: '.self::STATUSES[$ticket->status].'.' : '';
        if ($internal) {
            // Internal notes: only the assignee (or an admin who is not the writer) hears about it, never the employee.
            $this->tell($ticket->assignee ? collect([$ticket->assignee]) : collect(), $ticket, $actor->name.' added an internal note on '.$number.': '.$this->excerpt($body), $actor->id);
            if ($statusChanged) $this->tellEmployeeStatus($ticket, $actor);
        } elseif ($message->sender_type === 'staff') {
            $this->tell($ticket->employee?->user, $ticket, $actor->name.' replied on your support request '.$number.': '.$this->excerpt($body ?: 'sent a file').$statusText.$this->reopenHint($ticket), $actor->id);
            if ($ticket->assigned_to && (int) $ticket->assigned_to !== (int) $actor->id) $this->tell($ticket->assignee, $ticket, $actor->name.' replied on '.$number.': '.$this->excerpt($body ?: 'sent a file'), $actor->id);
        } else {
            $this->tell($this->staffToTell($ticket), $ticket, ($ticket->employee?->name ?? $actor->name).' replied on support request '.$number.': '.$this->excerpt($body ?: 'sent a file'), $actor->id);
        }

        return $message;
    }

    /** Hides a message from the employee (admin moderation). The text stays for staff and in the audit log. */
    public function hideMessage(SupportMessage $message, User $actor, string $reason): void
    {
        $ticket = $message->ticket;
        abort_unless(self::canManage($actor) && $this->staffCanView($actor, $ticket), 403);
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:255']], [], ['reason' => 'reason'])->validate();
        if ($message->hidden_at) return;
        DB::transaction(function () use ($message, $actor, $reason, $ticket) {
            $message->update(['hidden_at' => now(), 'hidden_by' => $actor->id, 'hidden_reason' => trim($reason)]);
            $this->event($ticket, $actor, 'message_hidden', (string) $message->id, null, trim($reason));
        });
        $this->audit->record('support.message_hidden', $message, ['ticket' => $ticket->number(), 'reason' => trim($reason), 'body' => \Illuminate\Support\Str::limit($message->body, 500), 'sender_id' => $message->user_id]);
    }

    /* ------------------------------------------------------------------ staff actions */

    public function setStatus(SupportTicket $ticket, User $actor, string $status, ?string $note = null): bool
    {
        abort_unless($this->canHandle($actor, $ticket), 403);
        if (! isset(self::STATUSES[$status])) throw ValidationException::withMessages(['status' => 'Choose a valid status.']);
        $changed = DB::transaction(fn () => $this->changeStatus($ticket, $actor, $status, $note));
        if ($changed) {
            $this->tellEmployeeStatus($ticket->refresh(), $actor);
            if ($ticket->assigned_to && (int) $ticket->assigned_to !== (int) $actor->id) $this->tell($ticket->assignee, $ticket, $actor->name.' set '.$ticket->number().' to '.self::STATUSES[$status].'.', $actor->id);
        }

        return $changed;
    }

    public function setPriority(SupportTicket $ticket, User $actor, string $priority): bool
    {
        abort_unless($this->canHandle($actor, $ticket), 403);
        if (! isset(self::PRIORITIES[$priority])) throw ValidationException::withMessages(['priority' => 'Choose a valid priority.']);
        if ($ticket->priority === $priority) return false;
        $from = $ticket->priority;
        DB::transaction(function () use ($ticket, $actor, $priority, $from) {
            $ticket->update(['priority' => $priority]);
            $this->event($ticket, $actor, 'priority', $from, $priority);
        });
        $this->audit->record('support.priority_changed', $ticket, ['ticket' => $ticket->number(), 'from' => $from, 'to' => $priority]);

        return true;
    }

    public function changeCategory(SupportTicket $ticket, User $actor, int $categoryId): bool
    {
        abort_unless(self::canManage($actor) && $this->staffCanView($actor, $ticket), 403);
        $category = SupportCategory::find($categoryId) ?? throw ValidationException::withMessages(['category' => 'Choose a category.']);
        if ((int) $ticket->category_id === $category->id) return false;
        if ($category->is_restricted && ! $actor->can('support.payroll')) throw ValidationException::withMessages(['category' => 'Only people with Salary & Payroll access can move a request there.']);
        if ($category->is_restricted && $ticket->assignee && ! $ticket->assignee->can('support.payroll')) {
            throw ValidationException::withMessages(['category' => $ticket->assignee->name.' cannot see '.$category->name.' requests. Assign it to someone who can first.']);
        }
        $from = $ticket->category?->name;
        DB::transaction(function () use ($ticket, $actor, $category, $from) {
            $ticket->update(['category_id' => $category->id]);
            $this->event($ticket, $actor, 'category', $from, $category->name);
        });
        $this->audit->record('support.category_changed', $ticket, ['ticket' => $ticket->number(), 'from' => $from, 'to' => $category->name]);
        $ticket->setRelation('category', $category);

        return true;
    }

    /**
     * Assigns (or unassigns with null). An admin (support.manage) assigns anyone who can handle the ticket;
     * a handler can only take an unassigned ticket for themself.
     */
    public function assign(SupportTicket $ticket, User $actor, ?int $assigneeId, ?string $note = null): bool
    {
        abort_unless($this->canHandle($actor, $ticket), 403);
        $manage = self::canManage($actor);
        if (! $manage && ! ($assigneeId === (int) $actor->id && $ticket->assigned_to === null)) abort(403, 'Only a support admin can assign requests to other people.');
        if ((int) $ticket->assigned_to === (int) $assigneeId) return false;
        $assignee = null;
        if ($assigneeId !== null) {
            $assignee = $this->handlers($ticket)->firstWhere('id', $assigneeId);
            if (! $assignee) throw ValidationException::withMessages(['assignee' => 'This person cannot handle this request (needs a support permission'.($ticket->category?->is_restricted ? ' and Salary & Payroll access' : '').').']);
        }
        $previous = $ticket->assignee;
        DB::transaction(function () use ($ticket, $actor, $assignee, $previous, $note) {
            $ticket->update(['assigned_to' => $assignee?->id, 'assigned_at' => $assignee ? now() : null]);
            $this->event($ticket, $actor, 'assigned', $previous ? (string) $previous->id : null, $assignee ? (string) $assignee->id : null,
                trim(($previous?->name ?? 'Nobody').' → '.($assignee?->name ?? 'Nobody').($note ? ' · '.$note : '')));
            if ($assignee && $ticket->status === 'open') $this->changeStatus($ticket, $actor, 'assigned');
            if (! $assignee && $ticket->status === 'assigned') $this->changeStatus($ticket, $actor, 'open');
        });
        $this->audit->record('support.assigned', $ticket, ['ticket' => $ticket->number(), 'from' => $previous?->id, 'to' => $assignee?->id]);

        $ticket->refresh()->load(['employee', 'category']);
        if ($assignee) $this->tell($assignee, $ticket, $actor->name.($previous ? ' reassigned' : ' assigned').' support request '.$ticket->number().' to you: '.$ticket->subject, $actor->id);
        if ($previous && (! $assignee || $previous->id !== $assignee->id)) $this->tell($previous, $ticket, $actor->name.' moved support request '.$ticket->number().' from you to '.($assignee?->name ?? 'nobody').'.', $actor->id);
        if ($assignee) $this->tell($ticket->employee?->user, $ticket, 'Your support request '.$ticket->number().' is now with '.$assignee->name.'.', $actor->id);

        return true;
    }

    /** Status change with its history line; returns false when it was already that status. Caller holds the transaction. */
    private function changeStatus(SupportTicket $ticket, User $actor, string $status, ?string $note = null): bool
    {
        if ($ticket->status === $status) return false;
        $from = $ticket->status;
        $updates = ['status' => $status];
        if ($status === 'resolved') $updates += ['resolved_at' => now(), 'resolved_by' => $actor->id];
        if ($status === 'closed') {
            $updates += ['closed_at' => now(), 'closed_by' => $actor->id];
            if (! $ticket->resolved_at) $updates += ['resolved_at' => now(), 'resolved_by' => $actor->id];
        }
        if (in_array($status, self::ACTIVE, true)) $updates += ['resolved_at' => null, 'resolved_by' => null, 'closed_at' => null, 'closed_by' => null];
        if (in_array($from, ['resolved', 'closed'], true) && in_array($status, self::ACTIVE, true)) $updates['reopen_count'] = $ticket->reopen_count + 1;
        $ticket->update($updates);
        $this->event($ticket, $actor, 'status', $from, $status, $note);
        $this->audit->record('support.status_changed', $ticket, ['ticket' => $ticket->number(), 'from' => $from, 'to' => $status]);

        return true;
    }

    private function tellEmployeeStatus(SupportTicket $ticket, User $actor): void
    {
        $text = match ($ticket->status) {
            'resolved' => 'Your support request '.$ticket->number().' is resolved.'.$this->reopenHint($ticket),
            'closed' => 'Your support request '.$ticket->number().' is closed.',
            'waiting_for_employee' => 'Your support request '.$ticket->number().' is waiting for your reply.',
            default => 'Your support request '.$ticket->number().' is now '.self::STATUSES[$ticket->status].'.',
        };
        $this->tell($ticket->employee?->user, $ticket, $text, $actor->id);
    }

    private function reopenHint(SupportTicket $ticket): string
    {
        return $ticket->status === 'resolved' && ($until = $this->reopenUntil($ticket)) ? ' Not solved? Reopen it by '.$until->format('d M Y').'.' : '';
    }

    /* ------------------------------------------------------------------ reading */

    public function markRead(SupportTicket $ticket, User $user): void
    {
        if (ImpersonationService::active()) return;
        SupportTicketRead::updateOrCreate(['ticket_id' => $ticket->id, 'user_id' => $user->id], ['last_read_at' => now()]);
    }

    /**
     * Tickets with a message (from someone else) newer than the person's last visit.
     * Employees never count internal notes.
     *
     * @return list<int>
     */
    public function unreadIds(User $user, iterable $ticketIds, bool $staff): array
    {
        $ids = collect($ticketIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) return [];
        $latest = SupportMessage::whereIn('ticket_id', $ids)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
            ->when(! $staff, fn ($q) => $q->where('is_internal', false))
            ->groupBy('ticket_id')->selectRaw('ticket_id, MAX(created_at) as latest')->pluck('latest', 'ticket_id');
        $reads = SupportTicketRead::where('user_id', $user->id)->whereIn('ticket_id', $ids)->pluck('last_read_at', 'ticket_id');

        return $latest->filter(fn ($at, $id) => ! isset($reads[$id]) || Carbon::parse($at)->gt(Carbon::parse($reads[$id])))->keys()->map(fn ($id) => (int) $id)->values()->all();
    }

    /** Messages the person may see, oldest first (employees: no internal notes). */
    public function visibleMessages(SupportTicket $ticket, bool $staff): Collection
    {
        return $ticket->messages()->with(['user.roles', 'attachments', 'hider'])->when(! $staff, fn ($q) => $q->where('is_internal', false))->orderBy('id')->get();
    }

    /** The role shown next to a sender: Employee, or their main role (Master Admin, HR …). */
    public function roleLabel(?User $user, SupportTicket $ticket, string $senderType): string
    {
        if ($senderType === 'employee') return 'Employee';

        return $user?->roles->pluck('name')->reject(fn ($r) => $r === 'Employee')->first() ?? 'Support';
    }

    /** History lines with readable values (names for assignment). */
    public function history(SupportTicket $ticket): Collection
    {
        $events = $ticket->events()->with('user')->orderBy('id')->get();
        $names = User::whereIn('id', $events->where('type', 'assigned')->flatMap(fn ($e) => [$e->from_value, $e->to_value])->filter()->map(fn ($v) => (int) $v)->unique())->pluck('name', 'id');

        return $events->map(function (SupportTicketEvent $e) use ($names) {
            $s = fn ($v) => self::STATUSES[$v] ?? $v;
            $text = match ($e->type) {
                'created' => 'Request raised',
                'status' => 'Status: '.$s($e->from_value).' → '.$s($e->to_value),
                'priority' => 'Priority: '.(self::PRIORITIES[$e->from_value] ?? $e->from_value).' → '.(self::PRIORITIES[$e->to_value] ?? $e->to_value),
                'category' => 'Category: '.$e->from_value.' → '.$e->to_value,
                'assigned' => 'Assigned: '.($e->from_value ? ($names[(int) $e->from_value] ?? 'someone') : 'Nobody').' → '.($e->to_value ? ($names[(int) $e->to_value] ?? 'someone') : 'Nobody'),
                'reopened' => 'Reopened by the employee',
                'message_hidden' => 'A message was hidden from the employee',
                default => ucfirst(str_replace('_', ' ', $e->type)),
            };

            return ['at' => $e->created_at, 'by' => $e->user?->name ?? 'System', 'text' => $text, 'note' => $e->type === 'assigned' ? null : $e->note, 'type' => $e->type];
        });
    }

    /* ------------------------------------------------------------------ files */

    /** @param list<UploadedFile> $files */
    private function storeFiles(SupportTicket $ticket, SupportMessage $message, array $files, User $actor): void
    {
        foreach (array_values($files) as $file) {
            if (! $file instanceof UploadedFile) continue;
            $path = $file->store('support-attachments/'.$ticket->id, 'local');
            if (! $path) throw ValidationException::withMessages(['attachments' => 'The file could not be saved. Please try again.']);
            SupportAttachment::create([
                'ticket_id' => $ticket->id, 'message_id' => $message->id, 'uploaded_by' => $actor->id,
                'original_name' => mb_substr($file->getClientOriginalName() ?: basename($path), 0, 255),
                'storage_path' => $path, 'mime_type' => $file->getMimeType(), 'size' => (int) $file->getSize(),
            ]);
        }
    }

    /** The employee sees files on visible messages only; staff see all (internal notes included). */
    public function canOpenAttachment(?User $user, SupportAttachment $attachment): bool
    {
        $ticket = $attachment->ticket;
        if (! $ticket) return false;
        if ($this->staffCanView($user, $ticket)) return true;
        $message = $attachment->message;

        return $this->isOwner($user, $ticket) && $message && ! $message->is_internal && ! $message->hidden_at;
    }

    public function attachmentResponse(SupportAttachment $attachment, bool $download = false): mixed
    {
        abort_unless(Storage::disk('local')->exists($attachment->storage_path), 404, 'The file is missing from storage.');
        $headers = ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff'];

        return $download || ! ($attachment->isImage() || $attachment->mime_type === 'application/pdf')
            ? Storage::disk('local')->download($attachment->storage_path, $attachment->original_name, $headers)
            : Storage::disk('local')->response($attachment->storage_path, $attachment->original_name, $headers, 'inline');
    }

    /* ------------------------------------------------------------------ reports */

    /**
     * Figures for the Support reports page, over the tickets the viewer may see.
     *
     * @param  array{from?: ?string, to?: ?string, category?: mixed, status?: ?string, assignee?: mixed, employee?: mixed}  $f
     */
    public function report(User $viewer, array $f): array
    {
        $base = function () use ($viewer, $f) {
            $q = $this->staffScope(SupportTicket::query(), $viewer);
            if (! empty($f['from'])) $q->where('created_at', '>=', Carbon::parse($f['from'])->startOfDay());
            if (! empty($f['to'])) $q->where('created_at', '<=', Carbon::parse($f['to'])->endOfDay());
            if (! empty($f['category'])) $q->where('category_id', (int) $f['category']);
            if (! empty($f['status'])) $f['status'] === 'active' ? $q->whereIn('status', self::ACTIVE) : $q->where('status', $f['status']);
            if (! empty($f['assignee'])) $f['assignee'] === 'none' ? $q->whereNull('assigned_to') : $q->where('assigned_to', (int) $f['assignee']);
            if (! empty($f['employee'])) $q->where('employee_id', (int) $f['employee']);

            return $q;
        };
        $tickets = $base()->get(['id', 'employee_id', 'category_id', 'assigned_to', 'status', 'priority', 'created_at', 'resolved_at', 'first_response_at']);
        $hours = fn (Collection $rows) => ($done = $rows->filter(fn ($t) => $t->resolved_at))->isEmpty() ? null : round($done->avg(fn ($t) => max(0, $t->created_at->diffInMinutes($t->resolved_at))) / 60, 1);
        $firstReply = fn (Collection $rows) => ($done = $rows->filter(fn ($t) => $t->first_response_at))->isEmpty() ? null : round($done->avg(fn ($t) => max(0, $t->created_at->diffInMinutes($t->first_response_at))) / 60, 1);
        $summary = fn (Collection $rows) => [
            'total' => $rows->count(),
            'open' => $rows->whereIn('status', ['open', 'assigned', 'in_progress'])->count(),
            'waiting' => $rows->where('status', 'waiting_for_employee')->count(),
            'pending' => $rows->whereIn('status', self::ACTIVE)->count(),
            'resolved' => $rows->where('status', 'resolved')->count(),
            'closed' => $rows->where('status', 'closed')->count(),
            'avg_hours' => $hours($rows),
            'first_reply_hours' => $firstReply($rows),
        ];

        $categories = SupportCategory::orderBy('sort_order')->pluck('name', 'id');
        $byCategory = $tickets->groupBy('category_id')->map(fn ($rows, $id) => ['name' => $categories[$id] ?? '—'] + $summary($rows))->sortByDesc('total')->values();
        $userNames = User::whereIn('id', $tickets->pluck('assigned_to')->filter()->unique())->pluck('name', 'id');
        $byHandler = $tickets->groupBy(fn ($t) => $t->assigned_to ?: 0)->map(fn ($rows, $id) => ['id' => (int) $id, 'name' => $id ? ($userNames[$id] ?? 'Former user') : 'Not assigned'] + $summary($rows))->sortByDesc('total')->values();
        $employees = AttendanceEmployee::whereIn('id', $tickets->pluck('employee_id')->unique())->get(['id', 'name', 'employee_code'])->keyBy('id');
        $byEmployee = $tickets->groupBy('employee_id')->map(fn ($rows, $id) => ['id' => (int) $id, 'name' => $employees[$id]->name ?? '—', 'code' => $employees[$id]->employee_code ?? '',
            'last' => $rows->max('created_at')] + $summary($rows))->sortByDesc('total')->values();

        return ['summary' => $summary($tickets), 'by_category' => $byCategory, 'by_handler' => $byHandler, 'by_employee' => $byEmployee,
            'by_priority' => collect(self::PRIORITIES)->map(fn ($label, $key) => $tickets->where('priority', $key)->count())];
    }

    /* ------------------------------------------------------------------ helpers */

    private function event(SupportTicket $ticket, ?User $actor, string $type, ?string $from, ?string $to, ?string $note = null): void
    {
        SupportTicketEvent::create(['ticket_id' => $ticket->id, 'user_id' => $actor?->id, 'type' => $type, 'from_value' => $from, 'to_value' => $to,
            'note' => $note !== null ? mb_substr($note, 0, 1000) : null, 'created_at' => now()]);
    }

    /** One notification per person (Notifier skips duplicates and the person who acted). */
    private function tell(iterable|User|null $users, SupportTicket $ticket, string $message, ?int $exceptUserId): void
    {
        Notifier::send($users, self::KIND, $ticket->id, $message, route('support.show', $ticket->id, false), $exceptUserId);
    }

    private function excerpt(string $text): string
    {
        return \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', trim($text)), 140);
    }
}
