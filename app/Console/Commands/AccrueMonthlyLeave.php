<?php

namespace App\Console\Commands;

use App\Services\LeaveAccrualService;
use Illuminate\Console\Command;

class AccrueMonthlyLeave extends Command
{
    protected $signature = 'leave:accrue {--year=} {--month=}';
    protected $description = 'Apply configured monthly leave accruals through the requested month';

    public function handle(LeaveAccrualService $service): int
    {
        $year = (int) ($this->option('year') ?: now()->year);
        $month = (int) ($this->option('month') ?: now()->month);
        $count = $service->accrueThrough($year, $month, (int) (auth()->id() ?? 0));
        $this->info("Leave accrual updated {$count} balances through {$year}-".str_pad((string) $month, 2, '0', STR_PAD_LEFT).'.');
        return self::SUCCESS;
    }
}
