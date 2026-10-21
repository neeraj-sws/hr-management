<?php

namespace App\Livewire\People;

use App\Livewire\Concerns\InteractsWithHr;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\LeaveRequest;
use App\Services\AttendanceService;
use Livewire\Component;

/** For a manager: their team as a chart, and everyone under them with today's attendance. */
class MyTeam extends Component
{
    use InteractsWithHr;

    public function render(AttendanceService $attendance)
    {
        $me = $this->requireEmployee();
        $people = AttendanceEmployee::with(['department', 'manager'])->where('is_active', true)->get();
        $children = $people->groupBy('manager_id');
        // Everyone below me: direct reports, their reports, and so on.
        $team = collect();
        $queue = [$me->id];
        while ($queue) {
            foreach ($children->get(array_shift($queue), collect()) as $person) {
                if ($team->has($person->id) || $person->id === $me->id) continue;
                $team->put($person->id, $person);
                $queue[] = $person->id;
            }
        }
        $ids = $team->keys();
        $sessions = AttendanceSession::with('employee')->whereIn('employee_id', $ids)->whereDate('work_date', today())->get()->keyBy('employee_id');
        $onLeave = LeaveRequest::with('leaveType')->whereIn('employee_id', $ids)->where('status', 'approved')
            ->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today())->get()->keyBy('employee_id');
        $rows = $team->sortBy(fn ($e) => sprintf('%04d%s', strlen($e->employee_code), $e->employee_code))->map(function ($e) use ($sessions, $onLeave, $attendance, $me) {
            $s = $sessions->get($e->id);
            $today = match (true) {
                $s && $attendance->isLate($s) => ['late', 'In '.$s->check_in->format('h:i A')],
                (bool) $s => [$s->check_out ? 'completed' : $s->status, 'In '.$s->check_in->format('h:i A').($s->check_out ? ' · out '.$s->check_out->format('h:i A') : '')],
                $onLeave->has($e->id) => ['on_leave', $onLeave[$e->id]->leaveType?->name],
                default => ['not_in', null],
            };

            return ['employee' => $e, 'direct' => $e->manager_id === $me->id, 'today' => $today];
        })->values();

        return $this->page('livewire.people.my-team', [
            'me' => $me,
            'rows' => $rows,
            'directCount' => $rows->where('direct', true)->count(),
        ], 'My team');
    }
}
