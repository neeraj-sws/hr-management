<?php

namespace App\Http\Controllers;

use App\Models\AttendanceEmployee;
use App\Services\ImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** POST /people/employees/{employee}/view-as and POST /view-as/stop (Master Admin only; see ImpersonationService). */
class ImpersonationController extends Controller
{
    public function start(Request $request, AttendanceEmployee $employee, ImpersonationService $impersonation): RedirectResponse
    {
        $impersonation->start($request, $employee);

        return redirect()->route('dashboard')->with('toast', ['message' => "You are now viewing as {$employee->name}.", 'type' => 'success']);
    }

    public function stop(Request $request, ImpersonationService $impersonation): RedirectResponse
    {
        abort_unless(ImpersonationService::active(), 404);
        $employeeId = $impersonation->stop($request);
        if (! auth()->check()) return redirect()->route('login');

        return $employeeId
            ? redirect()->route('people.employees.show', $employeeId)->with('toast', ['message' => 'Back to your own account.', 'type' => 'success'])
            : redirect()->route('dashboard');
    }
}
