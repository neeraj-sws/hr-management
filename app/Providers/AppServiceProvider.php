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

        // Re-apply route access rules on every Livewire update request, not only the first page load.
        Livewire::addPersistentMiddleware([EnsureActiveAccount::class, EnsureAccess::class]);
    }
}
