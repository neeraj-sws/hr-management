<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PeopleOperationsService;
use Illuminate\Console\Command;

class AssessCompliance extends Command
{
    protected $signature = 'compliance:assess {--from=} {--to=}';
    protected $description = 'Detect compliance issues (late, early check-out, absence, tasks, daily status) as pending penalties — nothing is deducted until approved';

    public function handle(PeopleOperationsService $people): int
    {
        $from = $this->option('from') ?: today()->subDay()->toDateString();
        $to = $this->option('to') ?: today()->subDay()->toDateString();
        $actor = User::role('Master Admin')->value('id') ?? User::value('id');
        $this->info("Created {$people->assessCompliance($from, $to, (int) $actor)} pending penalt(ies) for {$from} – {$to}.");

        return self::SUCCESS;
    }
}
