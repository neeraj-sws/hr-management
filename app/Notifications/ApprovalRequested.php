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
        'PenaltyAppeal' => 'approvals.center',
        'PayrollRun' => 'payroll.payslips',
        'SalaryPayment' => 'payroll.payslips',
        'TaskAssignment' => 'work.my-tasks',
        'TaskOverdue' => 'work.my-tasks',
        'TaskCompleted' => 'work.tasks',
        'TaskUpdated' => 'work.my-tasks',
        'PayslipRequest' => 'payroll.payslips',
        'LeaveReminder' => 'leave.requests',
        'LeaveEscalated' => 'leave.requests',
        'LeaveUrgent' => 'leave.requests',
        'TaskComment' => 'work.my-tasks',
        'ProjectMember' => 'work.my-projects',
        'ProjectManager' => 'work.projects',
        'ManagerAssigned' => 'people.teams',
        'ManagerChange' => 'approvals.pending',
        'AttendanceSession' => 'attendance.my',
        'AttendanceLate' => 'attendance.my',
        'AttendanceBreak' => 'attendance.dashboard',
        'AttendanceLongBreak' => 'attendance.dashboard',
        'ShiftReminder' => 'attendance.my',
        'CheckoutReminder' => 'attendance.my',
        'Payslip' => 'payroll.payslips',
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
        'PerformanceGoal' => 'performance.goals',
        'PerformanceFeedback' => 'performance.reviews',
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
        'attendance' => ['Late arrival, absence and break alerts', true],
        'reminders' => ['Daily status reminders and team blockers', true],
        'company' => ['Announcements, birthdays and anniversaries', true],
    ];

    private const KIND_GROUP = [
        'TaskAssignment' => 'work', 'TaskOverdue' => 'work', 'TaskComment' => 'work', 'TaskCompleted' => 'work', 'TaskUpdated' => 'work', 'ProjectMember' => 'work', 'ProjectManager' => 'work', 'DailyStatusSubmitted' => 'work', 'DailyStatusRequested' => 'work', 'Interview' => 'work', 'TrainingAssignment' => 'work', 'PerformanceReview' => 'work', 'PerformanceGoal' => 'work', 'PerformanceFeedback' => 'work',
        'AttendanceLate' => 'attendance', 'AttendanceAbsent' => 'attendance', 'AttendanceBreak' => 'attendance', 'AttendanceLongBreak' => 'attendance',
        'DailyStatusReminder' => 'reminders', 'DailyStatus' => 'reminders', 'ShiftReminder' => 'reminders', 'CheckoutReminder' => 'reminders',
        'Announcement' => 'company', 'Celebration' => 'company', 'WeeklyOff' => 'attendance', 'Shift' => 'attendance',
    ];

    /**
     * E-mail is on by default for things a person must act on or hear about
     * (approvals, their own requests, tasks and projects); routine reminders
     * and company news stay in the app unless the person turns e-mail on.
     */
    public const EMAIL_DEFAULTS = ['important' => true, 'work' => true, 'attendance' => true, 'reminders' => false, 'company' => false];

    /** Telegram (once the person has connected it in My profile): on for everything but company news. */
    public const TELEGRAM_DEFAULTS = ['important' => true, 'work' => true, 'attendance' => true, 'reminders' => true, 'company' => false];

    public static function groupFor(string $kind): string
    {
        return self::KIND_GROUP[$kind] ?? 'important';
    }

    /** A person's saved choice for one group and channel, or the default. */
    public static function preference(?array $saved, string $group, string $channel): bool
    {
        if ($channel === 'in_app' && ! (self::GROUPS[$group][1] ?? true)) return true;
        $default = match ($channel) { 'email' => self::EMAIL_DEFAULTS[$group] ?? false, 'telegram' => self::TELEGRAM_DEFAULTS[$group] ?? false, default => true };

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
        // Telegram: only to the person's own linked chat (My profile > Connect Telegram).
        if (self::preference($saved, $group, 'telegram') && ($notifiable->is_active ?? true) && \App\Notifications\Channels\TelegramChannel::enabled() && \App\Notifications\Channels\TelegramChannel::linked((int) $notifiable->getKey())) $channels[] = \App\Notifications\Channels\TelegramChannel::class;

        return $channels;
    }

    /** The in-app bell and the phone push are sent straight away (never waiting on a queue worker); only e-mail is queued. */
    public function viaConnections(): array
    {
        // Phone pushes go out at once too: a shift reminder that waits for a queue worker is useless.
        return ['database' => 'sync', \App\Notifications\Channels\ExpoPushChannel::class => 'sync', \App\Notifications\Channels\TelegramChannel::class => 'sync'];
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

    /** The person's own Telegram message: "SWS HRMS | LEAVE APPROVED", "Hello Priya," and the details (see TelegramTemplates). */
    public function toTelegram(object $notifiable): string
    {
        return \App\Support\TelegramTemplates::forNotification($this->kind, $this->recordId, $this->message, $notifiable, url($this->url ?? self::urlFor($this->kind, $this->message)));
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
