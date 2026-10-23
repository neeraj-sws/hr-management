<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PeopleOperationsService;
use App\Services\TwoFactorService;
use Illuminate\Console\Command;

/** Way back in when e-mail stops working and nobody can get a sign-in code. */
class TwoFactorOff extends Command
{
    protected $signature = 'security:two-factor-off {email? : Turn it off for this one account only}';
    protected $description = 'Turn off 2-step sign-in (for everyone, or for one account) when sign-in codes cannot be e-mailed';

    public function handle(PeopleOperationsService $people): int
    {
        if ($email = $this->argument('email')) {
            $user = User::where('email', $email)->first();
            if (! $user) {
                $this->error("No account with the e-mail {$email}.");

                return self::FAILURE;
            }
            $user->forceFill(['two_factor_enabled' => false])->save();
            $this->info("2-step sign-in turned off for {$email}. (If the company setting still requires it, use this command without an e-mail.)");

            return self::SUCCESS;
        }
        $actor = User::role('Master Admin')->value('id') ?? User::value('id');
        $people->setPolicy(TwoFactorService::POLICY, 'security', '2-step sign-in', ['mode' => 'off', 'apps' => false], null, (int) $actor, today()->toDateString());
        User::where('two_factor_enabled', true)->update(['two_factor_enabled' => false]);
        $this->info('2-step sign-in is off for everyone. Turn it on again in Settings > Security once e-mail works.');

        return self::SUCCESS;
    }
}
