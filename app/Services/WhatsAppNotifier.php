<?php

namespace App\Services;

use App\Jobs\SendWhatsAppMessage;
use App\Models\AttendanceEmployee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Decides who gets a WhatsApp message for an event and queues it.
 *
 * Recipients (whoever has a phone number on their employee record):
 *   - the employee's manager (WHATSAPP_NOTIFY_MANAGER),
 *   - users holding a permission (WHATSAPP_NOTIFY_PERMISSION, e.g. hr.manage),
 *   - fixed numbers from WHATSAPP_FIXED_RECIPIENTS.
 *
 * Off until WHATSAPP_ENABLED=true and a provider is configured. Messages are
 * dispatched after the HTTP response, so check-in / leave are never slowed.
 */
class WhatsAppNotifier
{
    public function enabled(): bool
    {
        return (bool) config('services.whatsapp.enabled');
    }

    private function eventOn(string $event): bool
    {
        $events = config('services.whatsapp.events') ?: [];

        return in_array($event, $events, true);
    }

    public function checkedIn(AttendanceEmployee $employee, string $time): void
    {
        $this->notify('check_in', $employee, "✅ *Check-in*\n{$employee->name} ({$employee->employee_code}) checked in at {$time}.");
    }

    public function checkedOut(AttendanceEmployee $employee, string $time, string $worked): void
    {
        $this->notify('check_out', $employee, "🔚 *Check-out*\n{$employee->name} ({$employee->employee_code}) checked out at {$time}. Worked {$worked}.");
    }

    public function leaveApplied(LeaveRequest $leave): void
    {
        $e = $leave->employee;
        if (! $e) {
            return;
        }
        $from = Carbon::parse($leave->starts_on)->format('d M');
        $to = Carbon::parse($leave->ends_on)->format('d M Y');
        $type = $leave->typeName() ?? 'Leave';
        $this->notify('leave_applied', $e, "🌴 *Leave request*\n{$e->name} ({$e->employee_code}) applied for {$type}: {$from} – {$to} ({$leave->days} day/s).\nReason: {$leave->reason}\nNeeds your approval.");
    }

    public function leaveDecided(LeaveRequest $leave): void
    {
        $e = $leave->employee;
        if (! $e) {
            return;
        }
        $type = $leave->typeName() ?? 'Leave';
        $this->notify('leave_decided', $e, "📋 *Leave {$leave->status}*\n{$e->name}'s {$type} ({$leave->days} day/s) was {$leave->status}.", includeEmployee: true);
    }

    /** Builds the recipient list and queues the message to each (after the response). */
    private function notify(string $event, AttendanceEmployee $employee, string $text, bool $includeEmployee = false): void
    {
        if (! $this->enabled() || ! $this->eventOn($event)) {
            return;
        }
        $numbers = [];
        if (config('services.whatsapp.notify_manager')) {
            $numbers[] = $employee->manager?->phone;
        }
        if ($includeEmployee) {
            $numbers[] = $employee->phone;
        }
        if ($permission = config('services.whatsapp.permission')) {
            foreach (User::permission($permission)->with('employeeProfile')->get() as $user) {
                $numbers[] = $user->employeeProfile?->phone;
            }
        }
        foreach ((array) config('services.whatsapp.fixed') as $fixed) {
            $numbers[] = $fixed;
        }

        $clean = [];
        foreach ($numbers as $n) {
            $n = $this->normalise($n);
            if ($n) {
                $clean[$n] = true;
            }
        }
        foreach (array_keys($clean) as $to) {
            SendWhatsAppMessage::dispatch($to, $text)->afterResponse();
        }
    }

    /** Digits only, with a default country code prepended to local 10-digit numbers. */
    private function normalise(?string $number): ?string
    {
        if (! $number) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $number);
        if (strlen($digits) < 10) {
            return null;
        }
        if (strlen($digits) === 10 && ($cc = config('services.whatsapp.default_cc'))) {
            $digits = $cc.$digits;
        }

        return $digits;
    }
}
