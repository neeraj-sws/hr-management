<?php

namespace App\Livewire\Attendance;

use App\Livewire\Concerns\InteractsWithHr;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Livewire\Component;

class MyAttendance extends Component
{
    use InteractsWithHr, \Livewire\WithFileUploads;

    /** Selfie for check-in/out (when Settings require it on the website). */
    public $photo = null;

    #[Url] public string $month = '';

    public function mount(): void
    {
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) ? $this->month : now()->format('Y-m');
    }

    /** The browser sends its position (when the employee allows it) with check-in and check-out. */
    private function place(?float $latitude, ?float $longitude, ?float $accuracy): ?array
    {
        return $latitude !== null && $longitude !== null ? ['latitude' => $latitude, 'longitude' => $longitude, 'accuracy' => $accuracy] : null;
    }

    public function checkIn(AttendanceService $attendance, ?float $latitude = null, ?float $longitude = null, ?float $accuracy = null): void
    {
        $attendance->checkIn($this->requireEmployee(), 'web', null, $this->place($latitude, $longitude, $accuracy), $this->photo);
        $this->reset('photo');
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

    public function checkOut(AttendanceService $attendance, ?float $latitude = null, ?float $longitude = null, ?float $accuracy = null): void
    {
        $attendance->checkOut($this->requireEmployee(), 'web', null, $this->place($latitude, $longitude, $accuracy), $this->photo);
        $this->reset('photo');
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
            'overtimeOn' => app(\App\Services\PeopleOperationsService::class)->overtimeEnabled($employee),
            'session' => $session,
            'worked' => $session ? $attendance->workedSeconds($session) : 0,
            'rules' => $attendance->rules($employee, today()->toDateString()),
            'late' => $session ? $attendance->isLate($session) : false,
            'selfieRequired' => $attendance->selfieRequired($employee, 'web'),
            'calendar' => app(\App\Services\PolicyConfigurationService::class)->forEmployee($employee, 'calendar.working_days', today()->toDateString(), ['days' => [1, 2, 3, 4, 5], 'holidays' => []]),
            'locationRequired' => (bool) (app(\App\Services\PolicyConfigurationService::class)->forEmployee($employee, 'attendance.location', today()->toDateString(), [])['required_for_web'] ?? false),
            'days' => $days,
            'summary' => $days->countBy('status'),
            'monthStart' => $start,
        ], 'My Attendance');
    }
}
