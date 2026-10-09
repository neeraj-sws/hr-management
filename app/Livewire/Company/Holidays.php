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
            'canEdit' => $this->user()->can('policies.manage'),
        ], 'Holidays');
    }
}
