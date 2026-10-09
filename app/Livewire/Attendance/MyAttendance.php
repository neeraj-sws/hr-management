<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\InteractsWithHr;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

class MyAttendance extends Component
{
    use InteractsWithHr;

    #[Url] public string $month = '';

    public function mount(): void
    {
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) ? $this->month : now()->format('Y-m');
    }

    public function checkIn(AttendanceService $attendance): void
    {
        $attendance->checkIn($this->requireEmployee());
        $this->toast('Checked in. Have a productive day!');
    }

    public function startBreak(AttendanceService $attendance): void
    {
        $attendance->startBreak($this->requireEmployee());
        $this->toast('Break started. Any running task timer was paused.');
    }

    public function resume(AttendanceService $attendance): void
    {
        $attendance->endBreak($this->requireEmployee());
        $this->toast('Welcome back — work resumed.');
    }

    public function checkOut(AttendanceService $attendance): void
    {
        $attendance->checkOut($this->requireEmployee());
        $this->toast('Checked out. See you tomorrow!');
    }

    public function render(AttendanceService $attendance)
    {
        $employee = $this->requireEmployee();
        $session = $attendance->today($employee)?->load('events');
        $start = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
        $end = $start->copy()->endOfMonth()->min(today());
        $days = $attendance->days($employee, $start, $end);

        return $this->page('livewire.attendance.my-attendance', [
            'employee' => $employee,
            'session' => $session,
            'worked' => $session ? $attendance->workedSeconds($session) : 0,
            'rules' => $attendance->rules($employee, today()->toDateString()),
            'late' => $session ? $attendance->isLate($session) : false,
            'days' => $days,
            'summary' => $days->countBy('status'),
            'monthStart' => $start,
        ], 'My Attendance');
    }
}
