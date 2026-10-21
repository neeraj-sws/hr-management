<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\AttendanceEmployee;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Company-wide information: announcements, birthdays/work anniversaries and the holiday calendar. */
class CompanyService
{
    public function __construct(private readonly AuditLogger $audit, private readonly PolicyConfigurationService $policies) {}

    /**
     * Creates or updates an announcement; a newly published one notifies its audience in the app.
     * Only with $email is the full announcement also e-mailed to everyone in the audience; without it
     * nobody gets an e-mail, whatever their notification preferences.
     */
    public function saveAnnouncement(array $data, User $author, ?Announcement $announcement = null, bool $email = false): Announcement
    {
        return DB::transaction(function () use ($data, $author, $announcement, $email) {
            $data['department_id'] = $data['audience'] === 'department' ? $data['department_id'] : null;
            $data['team_id'] = $data['audience'] === 'team' ? $data['team_id'] : null;
            $isNew = ! $announcement;
            $announcement ??= new Announcement(['created_by' => $author->id, 'published_at' => now()]);
            $announcement->fill($data)->save();
            $this->audit->record($isNew ? 'announcement.published' : 'announcement.updated', $announcement, ['audience' => $announcement->audience]);
            if ($isNew) {
                // The in-app notice never e-mails: an announcement is e-mailed only when "Also send by e-mail" is ticked.
                $notification = new ApprovalRequested('Announcement', $announcement->id, 'Announcement: '.$announcement->title, null, false);
                foreach ($this->audienceUsers($announcement) as $user) {
                    if ($user->id === $author->id) continue;
                    $user->notify($notification);
                    if ($email) rescue(fn () => $user->notify(new \App\Notifications\AnnouncementEmail($announcement)), null, true);
                }
                if ($email) $this->audit->record('announcement.emailed', $announcement, ['audience' => $announcement->audience]);
            }

            return $announcement;
        });
    }

    public function deleteAnnouncement(Announcement $announcement): void
    {
        $this->audit->record('announcement.deleted', $announcement, ['title' => $announcement->title]);
        // Remove the in-app notifications that pointed at this announcement, so a deleted
        // announcement no longer lingers in anyone's notification list.
        \Illuminate\Notifications\DatabaseNotification::where('data->kind', 'Announcement')
            ->where('data->record_id', $announcement->id)->delete();
        $announcement->delete();
    }

    /** @return Collection<int, User> */
    public function audienceUsers(Announcement $announcement): Collection
    {
        $employees = AttendanceEmployee::with('user')->where('is_active', true)->whereNotNull('user_id');
        if ($announcement->audience === 'department') $employees->where('department_id', $announcement->department_id);
        if ($announcement->audience === 'team') $employees->whereHas('teams', fn ($q) => $q->where('teams.id', $announcement->team_id));
        $users = $employees->get()->pluck('user')->filter(fn ($user) => $user?->is_active);
        if ($announcement->audience === 'all') $users = $users->merge(User::where('is_active', true)->doesntHave('employeeProfile')->get());

        return $users->unique('id')->values();
    }

    /**
     * Birthdays (for employees who share them) and work anniversaries in the
     * next $days days, soonest first. Birth years are never exposed.
     *
     * @return Collection<int, array{employee: AttendanceEmployee, type: string, date: Carbon, years: ?int, today: bool}>
     */
    public function celebrations(int $days = 7, ?Carbon $from = null): Collection
    {
        $from = ($from ?? today())->copy()->startOfDay();
        $until = $from->copy()->addDays($days);
        $events = collect();
        foreach (AttendanceEmployee::where('is_active', true)->where(fn ($q) => $q->whereNotNull('date_of_birth')->orWhereNotNull('joined_at'))->get() as $employee) {
            if ($employee->date_of_birth && $employee->share_birthday) {
                $next = $this->nextOccurrence($employee->date_of_birth, $from);
                if ($next->lte($until)) $events->push(['employee' => $employee, 'type' => 'birthday', 'date' => $next, 'years' => null, 'today' => $next->isSameDay($from)]);
            }
            if ($employee->joined_at) {
                $next = $this->nextOccurrence($employee->joined_at, $from);
                $years = $next->year - $employee->joined_at->year;
                if ($years >= 1 && $next->lte($until)) $events->push(['employee' => $employee, 'type' => 'anniversary', 'date' => $next, 'years' => $years, 'today' => $next->isSameDay($from)]);
            }
        }

        return $events->sortBy(fn ($event) => $event['date']->timestamp)->values();
    }

    /** Sends today's birthday/anniversary greetings to everyone once (scheduled daily). */
    public function notifyTodaysCelebrations(): int
    {
        $today = $this->celebrations(0)->where('today', true);
        if ($today->isEmpty()) return 0;
        $users = User::where('is_active', true)->get();
        foreach ($today as $event) {
            $first = explode(' ', $event['employee']->name)[0];
            $message = $event['type'] === 'birthday'
                ? "Today is {$event['employee']->name}'s birthday. Wish {$first} a happy birthday!"
                : "{$event['employee']->name} completes {$event['years']} ".str('year')->plural($event['years'])." with us today. Congratulations, {$first}!";
            foreach ($users as $user) {
                if ($user->id === $event['employee']->user_id) continue;
                $alreadySent = $user->notifications()->where('data->kind', 'Celebration')->where('data->record_id', $event['employee']->id)->whereDate('created_at', today())->exists();
                if (! $alreadySent) $user->notify(new ApprovalRequested('Celebration', $event['employee']->id, $message));
            }
        }

        return $today->count();
    }

    /**
     * Holidays from the working calendar (Settings > Calendar) with their names.
     *
     * @return Collection<int, array{date: Carbon, name: string, category: string, category_label: string, paid: bool}>
     */
    public function holidays(?AttendanceEmployee $employee, int $year): Collection
    {
        $calendar = $this->policies->forEmployee($employee, 'calendar.working_days', "{$year}-12-31", ['days' => [1, 2, 3, 4, 5], 'holidays' => []]);
        $names = $calendar['holiday_names'] ?? [];

        return collect($calendar['holidays'] ?? [])->filter(fn ($date) => str_starts_with((string) $date, (string) $year))->sort()->values()
            ->map(function ($date) use ($names, $calendar) {
                $type = \App\Support\WorkCalendar::holidayTypeOf($calendar, (string) $date);

                return ['date' => Carbon::parse($date), 'name' => (string) ($names[$date] ?? 'Holiday'), 'category' => $type['category'], 'category_label' => \App\Support\WorkCalendar::HOLIDAY_CATEGORIES[$type['category']], 'paid' => $type['paid']];
            });
    }

    /**
     * Tells employees when weekly offs change (a week-of-month off such as the
     * 3rd Saturday, or a weekly off moved to another day) — everyone for a
     * company change, or one employee for their own pattern — with the next dates.
     *
     * @return int people notified
     */
    public function announceWeeklyOffChange(array $before, array $after, User $actor, ?AttendanceEmployee $only = null): int
    {
        $norm = fn (array $c) => [\App\Support\WorkCalendar::normalise(['off_weeks' => $c['off_weeks'] ?? []])['off_weeks'], array_map(fn ($s) => [$s['from'], $s['to']], \App\Support\WorkCalendar::normalise(['shifts' => $c['shifts'] ?? []])['shifts']), array_map('intval', $c['days'] ?? [1, 2, 3, 4, 5])];
        if ($norm($before) === $norm($after)) return 0;

        $employees = $only ? collect([$only->loadMissing('user')]) : AttendanceEmployee::with('user')->where('is_active', true)->whereNotNull('user_id')->get();
        $sent = 0;
        foreach ($employees as $employee) {
            $calendar = $this->policies->forEmployee($employee, 'calendar.working_days', today()->toDateString(), ['days' => [1, 2, 3, 4, 5], 'holidays' => []]);
            $next = collect(\App\Support\WorkCalendar::upcomingSpecialOffs($calendar, today(), 60))->take(3)->map(fn ($o) => $o['date']->format('D d M'))->join(', ');
            $message = ($only ? 'Your weekly offs were updated' : 'Weekly offs were updated')." by {$actor->name}: ".\App\Support\WorkCalendar::describe($calendar).'.'.($next ? " Next special off days: {$next}." : '');
            $sent += Notifier::send($employee->user, 'WeeklyOff', $employee->id, $message, route('company.holidays', [], false), $actor->id);
        }
        $this->audit->record('holiday.weekly_off_announced', $only, ['people' => $sent]);

        return $sent;
    }

    /** The evening before a special off day (3rd Saturday, moved weekly off), everyone it applies to is reminded once. */
    public function remindTomorrowOff(?Carbon $today = null): int
    {
        $tomorrow = ($today ?? today())->copy()->addDay()->toDateString();
        $sent = 0;
        foreach (AttendanceEmployee::with('user')->where('is_active', true)->whereNotNull('user_id')->get() as $employee) {
            $calendar = $this->policies->forEmployee($employee, 'calendar.working_days', $tomorrow, ['days' => [1, 2, 3, 4, 5], 'holidays' => []]);
            $reason = \App\Support\WorkCalendar::specialOffReason($tomorrow, $calendar);
            if (! $reason || ! $employee->user) continue;
            if ($employee->user->notifications()->where('data->kind', 'WeeklyOff')->whereDate('created_at', today())->exists()) continue;
            $sent += Notifier::send($employee->user, 'WeeklyOff', $employee->id, 'Reminder: tomorrow, '.Carbon::parse($tomorrow)->format('l d M').", is your day off ({$reason}).", route('company.holidays', [], false));
        }

        return $sent;
    }

    /** ISO weekdays (1 = Monday) that are working days. */
    public function workingDays(?AttendanceEmployee $employee): array
    {
        return array_map('intval', $this->policies->forEmployee($employee, 'calendar.working_days', today()->toDateString(), ['days' => [1, 2, 3, 4, 5]])['days'] ?? [1, 2, 3, 4, 5]);
    }

    private function nextOccurrence(Carbon $date, Carbon $from): Carbon
    {
        $build = fn (int $year) => Carbon::create($year, $date->month, min($date->day, Carbon::create($year, $date->month, 1)->daysInMonth))->startOfDay();
        $next = $build($from->year);

        return $next->lt($from) ? $build($from->year + 1) : $next;
    }
}
