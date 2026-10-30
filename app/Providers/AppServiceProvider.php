<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Http\Middleware\EnsureAccess;
use App\Http\Middleware\EnsureActiveAccount;
use Livewire\Livewire;
use App\Models\AttendanceEmployee;
use App\Models\EmployeeNote;
use App\Models\CredentialVaultEntry;
use App\Models\OvertimeRequest;
use App\Policies\AttendanceEmployeePolicy;
use App\Policies\EmployeeNotePolicy;
use App\Policies\CredentialVaultEntryPolicy;
use App\Policies\OvertimeRequestPolicy;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(AttendanceEmployee::class,AttendanceEmployeePolicy::class);
        Gate::policy(EmployeeNote::class,EmployeeNotePolicy::class);
        Gate::policy(CredentialVaultEntry::class,CredentialVaultEntryPolicy::class);
        Gate::policy(OvertimeRequest::class,OvertimeRequestPolicy::class);

        // Telegram: the Super Admin's feed of HR events (after each save is committed; nothing is changed).
        foreach ([\App\Models\LeaveRequest::class, \App\Models\AttendanceCorrection::class, \App\Models\Task::class, \App\Models\Payslip::class, \App\Models\SalaryPayment::class, \App\Models\SalaryAdvance::class, \App\Models\EmployeePenalty::class, \App\Models\DailyWorkStatus::class] as $model) {
            $model::observe(\App\Observers\TelegramEventObserver::class);
        }

        // Re-apply route access rules on every Livewire update request, not only the first page load.
        Livewire::addPersistentMiddleware([EnsureActiveAccount::class, EnsureAccess::class]);

        // @hms($seconds) prints any duration as HH:MM:SS, the one format used across the app.
        \Illuminate\Support\Facades\Blade::directive('hms', fn (string $expression) => "<?php echo e(\\App\\Support\\Duration::hms({$expression})); ?>");

        // A day's attendance changing flags any draft payroll covering it for recalculation.
        \App\Models\AttendanceSession::observe(\App\Observers\AttendanceSessionObserver::class);
    }
}
