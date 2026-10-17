<?php

namespace App\Notifications;

use App\Models\PushToken;
use App\Notifications\Channels\ExpoPushChannel;
use App\Notifications\Channels\SafeMailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Database notification for workflow events (approval requests, decisions,
 * payroll, payments, task assignment). Queued and dispatched after commit.
 */
class ApprovalRequested extends Notification implements ShouldQueue
{
    use Queueable;

    /** Page an employee lands on for updates about their own records. */
    private const ROUTES = [
        'LeaveRequest' => 'leave.requests',
        'OvertimeRequest' => 'leave.overtime',
        'SalaryAdjustment' => 'payroll.salary',
        'SalaryAdvance' => 'payroll.advances',
        'EmployeePenalty' => 'compliance.penalties',
        'PayrollRun' => 'payroll.payslips',
        'SalaryPayment' => 'payroll.payslips',
        'TaskAssignment' => 'work.my-tasks',
        'TaskOverdue' => 'work.my-tasks',
        'TaskCompleted' => 'work.tasks',
        'TaskComment' => 'work.my-tasks',
        'ProjectMember' => 'work.my-projects',
        'ProjectManager' => 'work.projects',
        'ManagerAssigned' => 'people.teams',
        'ManagerChange' => 'approvals.pending',
        'AttendanceSession' => 'attendance.my',
        'AttendanceLate' => 'attendance.my',
        'WeeklyOff' => 'company.holidays',
        'PasswordChanged' => 'account.profile',
        'DailyStatusRequested' => 'work.daily-status',
        'LeaveBalance' => 'leave.balances',
        'SalaryProfile' => 'payroll.salary',
        'Shift' => 'attendance.my',
        'AttendanceAbsent' => 'attendance.my',
        'DailyStatus' => 'work.daily-status',
        'DailyStatusSubmitted' => 'work.daily-status',
        'DailyStatusReminder' => 'work.daily-status',
        'EmployeeAsset' => 'lifecycle.assets',
        'EmployeeDocument' => 'lifecycle.documents',
        'EmployeeLifecycleCase' => 'lifecycle.offboarding',
        'EmployeeFinalSettlement' => 'lifecycle.settlements',
        'EmployeeFinalSettlementPayment' => 'lifecycle.settlements',
        'PerformanceReview' => 'performance.reviews',
        'AttendanceCorrection' => 'attendance.corrections',
        'ExpenseClaim' => 'expenses.index',
        'Announcement' => 'company.announcements',
        'Celebration' => 'dashboard',
        'Interview' => 'recruitment.interviews',
        'TrainingAssignment' => 'training.my',
    ];

    /** group => [label, can be muted in the app] — everything not listed is "important" and always shown in the app. */
    public const GROUPS = [
        'important' => ['Approvals, my requests, payroll, attendance and penalties', false],
        'work' => ['Tasks, comments, interviews, training and reviews', true],
        'attendance' => ['Late arrival and absence alerts', true],
        'reminders' => ['Daily status reminders and team blockers', true],
        'company' => ['Announcements, birthdays and anniversaries', true],
    ];

    private const KIND_GROUP = [
        'TaskAssignment' => 'work', 'TaskOverdue' => 'work', 'TaskComment' => 'work', 'TaskCompleted' => 'work', 'ProjectMember' => 'work', 'ProjectManager' => 'work', 'DailyStatusSubmitted' => 'work', 'DailyStatusRequested' => 'work', 'Interview' => 'work', 'TrainingAssignment' => 'work', 'PerformanceReview' => 'work',
        'AttendanceLate' => 'attendance', 'AttendanceAbsent' => 'attendance',
        'DailyStatusReminder' => 'reminders', 'DailyStatus' => 'reminders',
        'Announcement' => 'company', 'Celebration' => 'company', 'WeeklyOff' => 'attendance', 'Shift' => 'attendance',
    ];

    /**
     * E-mail is on by default for things a person must act on or hear about
     * (approvals, their own requests, tasks and projects); routine reminders
     * and company news stay in the app unless the person turns e-mail on.
     */
    public const EMAIL_DEFAULTS = ['important' => true, 'work' => true, 'attendance' => true, 'reminders' => false, 'company' => false];

    public static function groupFor(string $kind): string
    {
        return self::KIND_GROUP[$kind] ?? 'important';
    }

    /** A person's saved choice for one group and channel, or the default. */
    public static function preference(?array $saved, string $group, string $channel): bool
    {
        if ($channel === 'in_app' && ! (self::GROUPS[$group][1] ?? true)) return true;
        $default = $channel === 'email' ? (self::EMAIL_DEFAULTS[$group] ?? false) : true;

        return (bool) (((array) ($saved[$group] ?? []))[$channel] ?? $default);
    }

    public function __construct(
        private readonly string $kind,
        private readonly int $recordId,
        private readonly string $message,
        private readonly ?string $url = null,
        private readonly bool $allowEmail = true, // false when the e-mail goes separately (e.g. an announcement e-mailed to everyone)
    ) {
        $this->afterCommit();
    }

    /** In-app unless the person muted an optional group; e-mail only where they asked for it. */
    public function via(object $notifiable): array
    {
        $group = self::groupFor($this->kind);
        $saved = (array) ($notifiable->notification_preferences ?? []);
        $channels = [];
        if (self::preference($saved, $group, 'in_app')) $channels[] = 'database';
        // Phone push goes with the in-app notification unless switched off for the group.
        if (in_array('database', $channels, true) && self::preference($saved, $group, 'push') && ExpoPushChannel::enabled() && PushToken::where('user_id', $notifiable->getKey())->exists()) $channels[] = ExpoPushChannel::class;
        // E-mail never breaks the action that triggered it (see SafeMailChannel).
        if ($this->allowEmail && self::preference($saved, $group, 'email') && ! empty($notifiable->email) && ($notifiable->is_active ?? true) && SafeMailChannel::enabled()) $channels[] = SafeMailChannel::class;

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->url ?? self::urlFor($this->kind, $this->message);

        return (new MailMessage)->subject(config('app.name', 'SWS HR').': '.\Illuminate\Support\Str::limit($this->message, 70))
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line($this->message)
            ->action('Open '.config('app.name', 'SWS HR'), url($url))
            ->line('You get this e-mail because of the notification settings in your profile.');
    }

    /** @return array{title: string, body: string, data: array} */
    public function toPush(object $notifiable): array
    {
        return ['title' => 'SWS HR', 'body' => \Illuminate\Support\Str::limit($this->message, 180), 'data' => ['kind' => $this->kind, 'record_id' => $this->recordId]];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['kind' => $this->kind, 'record_id' => $this->recordId, 'message' => $this->message, 'url' => $this->url ?? self::urlFor($this->kind, $this->message)];
    }

    /** Requests that "need review/approval" go to the approver inbox; everything else to the record's page. */
    public static function urlFor(string $kind, string $message): string
    {
        $route = str_contains(strtolower($message), 'needs') ? 'approvals.pending' : (self::ROUTES[$kind] ?? 'dashboard');
        if ($kind === 'PayrollRun' && str_contains(strtolower($message), 'needs')) $route = 'payroll.approvals';

        return route($route, absolute: false);
    }
}
