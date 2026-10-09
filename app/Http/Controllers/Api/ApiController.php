<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceEmployee;
use App\Models\User;
use Illuminate\Http\Request;

/** Base for endpoints used by signed-in people (web/mobile/desktop user tokens, not device tokens). */
abstract class ApiController extends Controller
{
    protected function apiUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403, 'A user API token is required for this endpoint.');

        return $user;
    }

    protected function apiEmployee(Request $request): AttendanceEmployee
    {
        $employee = $this->apiUser($request)->employeeProfile;
        abort_unless($employee && $employee->is_active, 403, 'An active employee profile is required.');

        return $employee;
    }

    protected function requirePermission(Request $request, string ...$permissions): User
    {
        $user = $this->apiUser($request);
        abort_unless($user->canAny($permissions), 403, 'You do not have permission for this action.');

        return $user;
    }
}
