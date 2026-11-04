<?php

namespace App\Console\Commands;

use App\Services\TrainingFeeService;
use Illuminate\Console\Command;

/** Course-fee installment reminders to trainees: 3 days before, on the due date, and once when overdue (HR too). */
class SendFeeReminders extends Command
{
    protected $signature = 'training:fee-reminders';
    protected $description = 'Remind trainees of course-fee installments (3 days before, on the day, once overdue)';

    public function handle(TrainingFeeService $fees): int
    {
        $this->info($fees->sendReminders().' reminder(s) sent.');

        return self::SUCCESS;
    }
}
