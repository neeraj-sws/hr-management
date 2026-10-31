<?php

namespace App\Console\Commands;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\ApprovalInbox;
use App\Services\TelegramNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Requests nobody has decided yet (night work, late arrival, attendance correction, expense claim, fine reason,
 * overtime): on Telegram, the Super Admin chat gets one list of all of them and each manager a list of their team's
 * (in the chat they connected), so nothing waits unseen. A request is listed once it has waited the set time
 * (2 hours; a late arrival for today at once) and at most once a day. Leave has its own reminders.
 * Runs every hour from 9 AM to 9 PM.
 */
class RemindPendingApprovals extends Command
{
    protected $signature = 'approvals:pending-reminders';
    protected $description = 'Telegram reminders to the Super Admin and managers for requests still waiting for approval';

    public const TYPES = [
        \App\Models\NightWorkRequest::class, \App\Models\LateArrivalRequest::class, \App\Models\AttendanceCorrection::class,
        \App\Models\ExpenseClaim::class, \App\Models\PenaltyAppeal::class, \App\Models\OvertimeRequest::class,
    ];

    public function handle(ApprovalInbox $inbox, TelegramNotificationService $telegram): int
    {
        $today = today()->toDateString();
        $due = ApprovalRequest::with('approvable')->where('status', 'pending')->whereIn('approvable_type', self::TYPES)->oldest('id')->get()
            ->filter(function (ApprovalRequest $a) use ($today) {
                $subject = $a->approvable;
                if (! $subject || ($subject->status ?? 'pending') !== 'pending') return false;
                // A late arrival for today cannot wait; anything else after 2 hours.
                $urgent = $subject instanceof \App\Models\LateArrivalRequest && $subject->work_date?->toDateString() === $today;

                return ($urgent || $a->created_at->lte(now()->subHours(2))) && ! Cache::has("approval-reminded:{$a->id}:{$today}");
            })->values();
        if ($due->isEmpty()) {
            $this->info('Nothing waiting.');

            return self::SUCCESS;
        }

        $line = fn (ApprovalRequest $a, int $i) => ($i + 1).'. '.$inbox->typeLabel($a->approvable_type).': '.$inbox->summary($a).' · waiting '.self::waited($a);
        // The Super Admin: one list of everything waiting.
        $telegram->toAdmin('approvals_pending', 'approvals-pending:'.now()->format('Y-m-d-H'), TelegramNotificationService::format('Waiting for approval ('.$due->count().')',
            [...$due->values()->map($line)->all(), 'No action taken yet. Approve or reject them in Approvals.']));

        // Each manager (or the person the request was sent to): their own list, in the chat they connected.
        $byManager = $due->groupBy(function (ApprovalRequest $a) use ($inbox) {
            return $a->approver_id ?: $inbox->subjectEmployee($a->approvable)?->manager?->user_id;
        });
        $managers = 0;
        foreach ($byManager as $userId => $items) {
            $user = $userId ? User::find($userId) : null;
            // Already in the Super Admin list when the manager's chat is that chat.
            if (! $user || ($telegram->adminChatId() && $telegram->chatFor($user->id) === $telegram->adminChatId())) continue;
            $telegram->toUser($user, null, 'approvals-pending:'.now()->format('Y-m-d-H'), TelegramNotificationService::format('Waiting for your approval ('.$items->count().')',
                [...$items->values()->map($line)->all(), 'Please approve or reject them in the SWS HR app or website (Approvals).']));
            $managers++;
        }
        foreach ($due as $a) Cache::put("approval-reminded:{$a->id}:{$today}", true, now()->endOfDay());
        $this->info("{$due->count()} request(s) waiting: Super Admin and {$managers} manager(s) reminded.");

        return self::SUCCESS;
    }

    private static function waited(ApprovalRequest $a): string
    {
        $hours = (int) $a->created_at->diffInHours(now());

        return $hours < 24 ? max(1, $hours).' h' : intdiv($hours, 24).' day'.(intdiv($hours, 24) === 1 ? '' : 's');
    }
}
