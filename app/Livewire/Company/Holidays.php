<?php

namespace App\Livewire\Company;

use App\Livewire\Concerns\InteractsWithHr;
use App\Services\CompanyService;
use Livewire\Attributes\Url;
use Livewire\Component;

class Holidays extends Component
{
    use InteractsWithHr;

    #[Url] public int $year = 0;

    public function mount(): void
    {
        if ($this->year < 2000 || $this->year > 2100) $this->year = (int) today()->year;
    }

    public function render(CompanyService $company)
    {
        $employee = $this->ownEmployee();
        $holidays = $company->holidays($employee, $this->year);
        $weekdays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        $working = $company->workingDays($employee);

        return $this->page('livewire.company.holidays', [
            'holidays' => $holidays,
            'next' => $holidays->first(fn ($h) => $h['date']->gte(today())),
            'weeklyOff' => collect($weekdays)->except($working)->values(),
            // Week-of-month offs (e.g. 3rd Saturday) and weekly offs moved to another day this year.
            'pattern' => \App\Support\WorkCalendar::describe($calendar = app(\App\Services\PolicyConfigurationService::class)->forEmployee($employee, 'calendar.working_days', today()->toDateString(), ['days' => [1, 2, 3, 4, 5]])),
            'shifts' => collect((array) ($calendar['shifts'] ?? []))->filter(fn ($s) => str_starts_with((string) $s['from'], (string) $this->year) || str_starts_with((string) $s['to'], (string) $this->year))->values(),
            'canEdit' => $this->user()->can('policies.manage'),
        ], 'Holidays');
    }
}
