<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * One-command setup for a fresh environment.
 *
 *   php artisan sws:install            Set up without touching existing data (safe to re-run).
 *   php artisan sws:install --fresh    WIPE the database, then set up clean (asks to confirm).
 *   php artisan sws:install --demo     Also load fake demo data (test/UAT only, never production).
 */
class Install extends Command
{
    protected $signature = 'sws:install {--fresh : Drop ALL tables first (deletes every record)} {--demo : Also seed fake demo data (never on production)}';
    protected $description = 'Fresh setup: migrate, seed roles/permissions/admin, link storage and cache config';

    public function handle(): int
    {
        if (app()->environment('production') && $this->option('fresh')) {
            if (! $this->confirm('PRODUCTION + --fresh will DELETE ALL DATA. Are you absolutely sure?', false)) {
                $this->warn('Cancelled.');

                return self::FAILURE;
            }
        }
        if (! env('SEED_USER_PASSWORD') || strlen((string) env('SEED_USER_PASSWORD')) < 12) {
            $this->error('Set SEED_USER_PASSWORD in .env to at least 12 characters before installing.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->components->task('Dropping and re-creating all tables', fn () => Artisan::call('migrate:fresh', ['--force' => true]) === 0);
        } else {
            $this->components->task('Running migrations', fn () => Artisan::call('migrate', ['--force' => true]) === 0);
        }

        $this->components->task('Seeding roles, permissions and the first admin', fn () => Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]) === 0);

        if ($this->option('demo')) {
            if (app()->environment('production')) {
                $this->error('Refusing to seed demo data on production.');

                return self::FAILURE;
            }
            $this->components->task('Seeding fake demo data', fn () => Artisan::call('db:seed', ['--class' => 'DemoDataSeeder', '--force' => true]) === 0);
            Artisan::call('db:seed', ['--class' => 'HrmsExtensionsDemoSeeder', '--force' => true]);
        }

        $this->components->task('Linking storage', fn () => Artisan::call('storage:link') === 0 || true);

        if (app()->environment('production')) {
            foreach (['config:cache', 'route:cache', 'view:cache'] as $cmd) {
                $this->components->task("Caching ({$cmd})", fn () => Artisan::call($cmd) === 0);
            }
        }

        $this->newLine();
        $this->info('SWS HR is set up.');
        $this->line('  Admin login: '.env('SEED_USER_EMAIL', 'admin@example.com'));
        $this->line('  Password:    the SEED_USER_PASSWORD from your .env');
        $this->warn('  Change this password right after your first sign-in.');

        return self::SUCCESS;
    }
}
