<?php

namespace App\Support;

use App\Models\ApprovalRequest;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceEmployee;
use App\Models\ExpenseClaim;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\ApprovalInbox;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One place that decides how a notification looks, for the bell, the Notifications page and the app:
 * its type (icon and colours), a title with the person's name in bold, a small details line,
 * an optional quote, the relative time, and whether it needs the reader's approval.
 */
class NotificationPresenter
{
    /** type => [tag label, web icon, app icon (MaterialCommunityIcons), background, icon colour, filter chip] */
    public const TYPES = [
        'correction' => ['Attendance correction', 'clock-edit', 'clock-edit-outline', '#FAEEDA', '#854F0B', 'attendance'],
        'daily_status' => ['Daily status', 'message', 'message-text-outline', '#E6F1FB', '#185FA5', 'daily_status'],
        'late_self' => ['Late', 'alarm', 'alarm', '#FCEBEB', '#A32D2D', 'attendance'],
        'late_team' => ['Teammate late', 'users', 'account-multiple-outline', '#F1EFE8', '#5F5E5A', 'attendance'],
        'task' => ['Task', 'check-circle', 'check-circle-outline', '#E1F5EE', '#085041', 'tasks'],
        'payroll' => ['Payroll', 'currency', 'currency-inr', '#EEEDFE', '#3C3489', 'payroll'],
        'leave' => ['Leave', 'calendar', 'calendar-blank-outline', '#FAEEDA', '#854F0B', 'leave'],
        'attendance' => ['Attendance', 'clock', 'clock-outline', '#E6F1FB', '#185FA5', 'attendance'],
        'other' => ['Update', 'bell', 'bell-outline', '#F1EFE8', '#5F5E5A', 'other'],
    ];

    /** Filter chips on the page and in the app (besides All and Needs action). */
    public const CHIPS = ['attendance' => 'Attendance', 'daily_status' => 'Daily status', 'tasks' => 'Tasks', 'payroll' => 'Payroll'];

    private const KIND_TYPE = [
        'AttendanceCorrection' => 'correction',
        'DailyStatusSubmitted' => 'daily_status', 'DailyStatusRequested' => 'daily_status', 'DailyStatusReminder' => 'daily_status', 'DailyStatus' => 'daily_status',
        'TaskAssignment' => 'task', 'TaskCompleted' => 'task', 'TaskOverdue' => 'task', 'TaskComment' => 'task', 'ProjectMember' => 'task', 'ProjectManager' => 'task', 'TrainingAssignment' => 'task', 'PerformanceGoal' => 'task', 'PerformanceReview' => 'task', 'PerformanceFeedback' => 'task',
        'PayrollRun' => 'payroll', 'SalaryPayment' => 'payroll', 'Payslip' => 'payroll', 'SalaryAdjustment' => 'payroll', 'SalaryAdvance' => 'payroll', 'SalaryProfile' => 'payroll', 'EmployeeFinalSettlement' => 'payroll', 'EmployeeFinalSettlementPayment' => 'payroll',
        'LeaveRequest' => 'leave', 'OvertimeRequest' => 'leave', 'LeaveBalance' => 'leave', 'ExpenseClaim' => 'leave',
        'MonitoringRequest' => 'other', 'ScreenCapture' => 'other',
        'AttendanceAbsent' => 'attendance', 'AttendanceBreak' => 'attendance', 'AttendanceLongBreak' => 'attendance', 'AttendanceSession' => 'attendance', 'ShiftReminder' => 'attendance', 'CheckoutReminder' => 'attendance', 'AutoCheckout' => 'attendance', 'Shift' => 'attendance', 'WeeklyOff' => 'attendance',
    ];

    /** Requests that can be approved or rejected straight from the notification. */
    public const ACTION_MODELS = [
        'AttendanceCorrection' => AttendanceCorrection::class,
        'LeaveRequest' => LeaveRequest::class,
        'OvertimeRequest' => OvertimeRequest::class,
        'ExpenseClaim' => ExpenseClaim::class,
        'MonitoringRequest' => \App\Models\MonitoringRequest::class,
    ];

    /** @var list<string>|null known people, longest name first */
    private ?array $names = null;

    public function __construct(private readonly User $user) {}

    public static function typeFor(?string $kind, string $message): string
    {
        if ($kind === 'AttendanceLate') return str_starts_with($message, 'You') ? 'late_self' : 'late_team';

        return self::KIND_TYPE[$kind ?? ''] ?? 'other';
    }

    /** @return list<string> notification kinds shown under a chip */
    public static function kindsForChip(string $chip): array
    {
        $types = array_keys(array_filter(self::TYPES, fn ($t) => $t[5] === $chip));
        $kinds = array_keys(array_filter(self::KIND_TYPE, fn ($type) => in_array($type, $types, true)));
        if ($chip === 'attendance') $kinds[] = 'AttendanceLate';

        return $kinds;
    }

    /** The colours and icons, for the app (the same table the website uses). */
    public static function typesForApi(): array
    {
        return collect(self::TYPES)->map(fn ($t, $key) => ['type' => $key, 'label' => $t[0], 'icon' => $t[2], 'bg' => $t[3], 'fg' => $t[4], 'chip' => $t[5]])->values()->all();
    }

    /** "45 min", "1 h 5 min" for old messages that still say 00:45:00 (and "0 min after" phrasing). */
    public static function tidy(string $message): string
    {
        $message = preg_replace('/checked in late (?:today )?at (\d{1,2}:\d{2} [AP]M) \((.+?) after (?:your|the) start time\)\.?/u', 'checked in late at $1 · $2 late.', $message);

        return preg_replace_callback('/\b(\d{2,}):(\d{2}):(\d{2})\b/', fn ($m) => Duration::words((int) $m[1] * 60 + (int) $m[2] + ((int) $m[3] >= 30 ? 1 : 0)), $message);
    }

    /** "Just now", "12 min ago", "3h ago", "Yesterday", then "24 Oct" (with the year when not this year). */
    public static function relative(Carbon $at): string
    {
        $now = now();
        if ($at->isSameDay($now)) {
            $minutes = (int) $at->diffInMinutes($now);

            return $minutes < 1 ? 'Just now' : ($minutes < 60 ? "{$minutes} min ago" : intdiv($minutes, 60).'h ago');
        }
        if ($at->isSameDay($now->copy()->subDay())) return 'Yesterday';

        return $at->format($at->year === $now->year ? 'd M' : 'd M Y');
    }

    public static function dayGroup(Carbon $at): string
    {
        return $at->isToday() ? 'Today' : ($at->isYesterday() ? 'Yesterday' : 'Earlier');
    }

    /**
     * @param  iterable<DatabaseNotification>  $notifications
     * @return list<array<string, mixed>>
     */
    public function present(iterable $notifications): array
    {
        $notifications = collect($notifications);
        $approvals = $this->approvalsFor($notifications);

        return $notifications->map(fn (DatabaseNotification $n) => $this->one($n, $approvals))->values()->all();
    }

    /** Same-day "teammate checked in late" alerts become one row: "2 teammates checked in late". */
    public function group(array $items): array
    {
        $out = [];
        $buckets = [];
        foreach ($items as $item) {
            if ($item['type'] === 'late_team' && ! $item['needs_action']) {
                $key = 'late|'.substr($item['created_at'], 0, 10);
                if (isset($buckets[$key])) {
                    $out[$buckets[$key]]['children'][] = $item;
                    continue;
                }
                $buckets[$key] = count($out);
                $item['children'] = [$item];
            }
            $out[] = $item;
        }

        return array_map(function (array $item) {
            if (count($item['children'] ?? []) < 2) {
                unset($item['children']);

                return $item;
            }
            $children = $item['children'];
            $who = collect($children)->map(fn ($c) => trim(explode(' ', (string) ($c['actor'] ?? ''))[0].($c['details'] ? ' ('.str_replace(' late', '', $c['details']).')' : '')))->filter()->join(', ');

            return [...$item, 'id' => 'group-'.$item['id'], 'grouped' => true, 'ids' => array_column($children, 'id'),
                'actor' => count($children).' teammates', 'title' => count($children).' teammates checked in late',
                'parts' => [['text' => count($children).' teammates', 'bold' => true], ['text' => ' checked in late', 'bold' => false]],
                'details' => $who, 'quote' => null, 'unread' => collect($children)->contains('unread', true), 'children' => $children];
        }, $out);
    }

    /** Counts for the chips: everything the person has (latest 1000), unread, and what needs their action. */
    public function counts(): array
    {
        $rows = $this->user->notifications()->latest()->limit(1000)->get(['id', 'type', 'data', 'read_at', 'created_at', 'notifiable_type', 'notifiable_id']);
        $chips = array_fill_keys(array_keys(self::CHIPS), 0);
        foreach ($rows as $n) {
            $chip = self::TYPES[self::typeFor($n->data['kind'] ?? null, (string) ($n->data['message'] ?? ''))][5];
            if (isset($chips[$chip])) $chips[$chip]++;
        }

        return ['all' => $rows->count(), 'unread' => $rows->whereNull('read_at')->count(), 'needs_action' => count($this->needsAction()), ...$chips];
    }

    /** Notifications whose request is still waiting for this person's decision (newest first). */
    public function needsAction(int $limit = 300): array
    {
        $candidates = $this->user->notifications()->whereIn('data->kind', array_keys(self::ACTION_MODELS))
            ->where('data->message', 'like', '%needs review%')->latest()->orderBy('id')->limit($limit)->get();

        return array_values(array_filter($this->present($candidates), fn ($item) => $item['needs_action']));
    }

    /** @return array<string, ApprovalRequest> keyed "Kind|record_id" */
    private function approvalsFor(Collection $notifications): array
    {
        $wanted = $notifications->filter(fn ($n) => isset(self::ACTION_MODELS[$n->data['kind'] ?? '']) && str_contains((string) ($n->data['message'] ?? ''), 'needs review'))
            ->groupBy(fn ($n) => $n->data['kind'])->map(fn ($group) => $group->pluck('data.record_id')->filter()->unique()->values());
        $out = [];
        foreach ($wanted as $kind => $ids) {
            ApprovalRequest::with(['approvable', 'approver'])->where('approvable_type', self::ACTION_MODELS[$kind])->whereIn('approvable_id', $ids->all())
                ->latest('id')->get()->each(function (ApprovalRequest $a) use (&$out, $kind) { $out[$kind.'|'.$a->approvable_id] ??= $a; });
        }

        return $out;
    }

    private function one(DatabaseNotification $n, array $approvals): array
    {
        $kind = $n->data['kind'] ?? null;
        $message = self::tidy((string) ($n->data['message'] ?? 'You have an update.'));
        $type = self::typeFor($kind, $message);
        [$actor, $rest, $details, $quote, $bold] = $this->parse($message);

        $approval = $approvals[$kind.'|'.($n->data['record_id'] ?? 0)] ?? null;
        $needsAction = false;
        $decision = null;
        if ($approval) {
            if ($approval->status === 'pending') {
                $needsAction = app(ApprovalInbox::class)->canDecide($this->user, $approval);
            } elseif (in_array($approval->status, ['approved', 'rejected'], true)) {
                $by = $approval->approver_id === $this->user->id ? 'you' : ($approval->approver?->name ?? 'someone');
                $decision = ucfirst($approval->status).' by '.$by;
            }
        }
        $parts = array_values(array_filter([
            $actor !== null ? ['text' => $actor, 'bold' => true] : null,
            ['text' => $rest, 'bold' => false],
            $bold !== null ? ['text' => $bold, 'bold' => true] : null,
        ], fn ($p) => $p !== null && $p['text'] !== ''));
        $t = self::TYPES[$type];

        return [
            'id' => $n->id, 'kind' => $kind, 'record_id' => $n->data['record_id'] ?? null, 'type' => $type, 'tag' => $t[0],
            'icon' => $t[1], 'app_icon' => $t[2], 'bg' => $t[3], 'fg' => $t[4], 'chip' => $t[5],
            'actor' => $actor, 'parts' => $parts, 'title' => implode('', array_column($parts, 'text')),
            'details' => $details, 'quote' => $quote, 'message' => $message,
            'unread' => $n->read_at === null, 'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at->toIso8601String(), 'time' => self::relative($n->created_at), 'day' => self::dayGroup($n->created_at),
            'needs_action' => $needsAction, 'approval_id' => $needsAction ? $approval->id : null, 'decision' => $decision,
            'url' => $n->data['url'] ?? null, 'open_url' => route('system.notifications.open', $n->id, false),
        ];
    }

    /** @return array{0: ?string, 1: string, 2: ?string, 3: ?string, 4: ?string} actor, rest of the title, details, quote, bold tail */
    private function parse(string $m): array
    {
        $date = fn (string $d) => rescue(fn () => Carbon::parse($d)->format('d M'), $d, false);
        if (preg_match('/^An attendance correction from (.+?) for (\d{1,2} \w{3} \d{4}) \((.*?)\) needs review\.(?: Reason: (.*))?$/su', $m, $x)) {
            return [$x[1], ' requested attendance correction for '.$date($x[2]), $x[3] ?: null, ($x[4] ?? '') !== '' ? $x[4] : null, null];
        }
        if (preg_match('/^A leave request from (.+?) needs review: (.*)$/su', $m, $x)) return [$x[1], ' requested leave', rtrim($x[2], '.'), null, null];
        if (preg_match('/^A screen capture request from (.+?) for (.+?) needs review\. Reason: (.*)$/su', $m, $x)) return [$x[1], ' asks to turn on screen capture for '.$x[2], null, $x[3], null];
        if (preg_match('/^An expense claim from (.+?) \((.*)\) needs review\.?$/su', $m, $x)) return [$x[1], ' submitted an expense claim', $x[2], null, null];
        if (preg_match('/^(.+?) submitted the daily status for (.+?)(?: — (.*)|: (.*))?$/su', $m, $x)) {
            $quote = trim(($x[3] ?? '') !== '' ? $x[3] : ($x[4] ?? ''));

            return [$x[1], ' submitted daily status for '.$x[2], null, $quote !== '' ? $quote : null, null];
        }
        if (preg_match('/^(.+?) checked in late at (\d{1,2}:\d{2} [AP]M) · (.+?)\.?$/su', $m, $x)) {
            return $x[1] === 'You' ? [null, 'You checked in late at ', $x[3], null, $x[2]] : [$x[1], ' checked in late at ', $x[3], null, $x[2]];
        }
        // Anything else: the person's name in bold when the message starts with it, a long tail after ": " as a quote.
        $actor = collect($this->names())->first(fn ($name) => str_starts_with($m, $name.' '));
        $rest = $actor ? substr($m, strlen($actor)) : $m;
        $quote = null;
        if (($pos = strpos($rest, ': ')) !== false && mb_strlen($rest) - $pos > 40) {
            $quote = trim(substr($rest, $pos + 2));
            $rest = substr($rest, 0, $pos);
        }

        return [$actor, $rest, null, $quote, null];
    }

    /** @return list<string> */
    private function names(): array
    {
        return $this->names ??= AttendanceEmployee::query()->pluck('name')->merge(User::query()->pluck('name'))
            ->filter(fn ($n) => mb_strlen((string) $n) > 2)->unique()->sortByDesc(fn ($n) => mb_strlen($n))->values()->all();
    }
}
