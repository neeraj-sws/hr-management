<?php

namespace App\Console\Commands;

use App\Services\GoogleSheetsSync;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncGoogleSheets extends Command
{
    protected $signature = 'sheets:sync {--from= : First day (Y-m-d), default yesterday} {--to= : Last day (Y-m-d), default today}';
    protected $description = 'Send attendance (and the other chosen reports) to the connected Google Sheet';

    public function handle(GoogleSheetsSync $sheets): int
    {
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : today();
        $from = $this->option('from') ? Carbon::parse($this->option('from')) : $to->copy()->subDay();
        $result = $sheets->sync($from, $to);
        $result['ok'] ? $this->info($result['message']) : $this->warn($result['message']);

        return self::SUCCESS;
    }
}
