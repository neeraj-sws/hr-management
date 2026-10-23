<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\AttendanceSessionResource;
use App\Http\Resources\EmployeeResource;
use App\Services\AttendanceService;
use App\Services\PolicyConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends ApiController
{
    public function show(Request $request, AttendanceService $attendance, PolicyConfigurationService $policies): JsonResponse
    {
        $user = $this->apiUser($request);
        $employee = $user->employeeProfile?->load(['department', 'manager']);
        $session = $employee ? $attendance->today($employee) : null;

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            'employee' => $employee ? (new EmployeeResource($employee))->resolve() : null,
            'today' => $session ? array_merge((new AttendanceSessionResource($session))->resolve(), ['worked_seconds' => $attendance->workedSeconds($session)]) : null,
            'unread_notifications' => $user->unreadNotifications()->count(),
            'schedule' => $employee ? (fn ($rules) => ['shift' => $rules['shift'], 'starts_at' => $rules['work_start'] ? substr($rules['work_start'], 0, 5) : null, 'grace_minutes' => $rules['grace_minutes']])($attendance->rules($employee, today()->toDateString())) : null,
            'policies' => [
                'location_required' => (bool) ($policies->forEmployee($employee, 'attendance.location', today()->toDateString(), [])['required_for_mobile'] ?? false),
                'selfie_required' => $employee ? $attendance->selfieRequired($employee, 'mobile') : false,
                'attendance_sources' => $employee ? app(\App\Services\AttendanceService::class)->allowedSources($employee) : ['web', 'mobile', 'desktop'],
                'app_usage' => $employee ? app(\App\Services\AppUsageService::class)->enabledFor($employee) : false,
                'window_titles' => $employee ? app(\App\Services\AppUsageService::class)->titlesEnabledFor($employee) : false,
                // Overtime switched off in Settings > Payroll: the apps hide their overtime screens.
                'overtime' => app(\App\Services\PeopleOperationsService::class)->overtimeEnabled($employee),
                'screen_capture' => $employee ? (function ($captures) use ($employee, $user) {
                    $policy = $captures->policy($employee);

                    return [...$policy, 'acknowledged' => $captures->isAcknowledged($user, $policy), 'notice' => $captures->describe($policy), 'policy_url' => route('monitoring.policy')];
                })(app(\App\Services\ScreenCaptureService::class)) : null,
                'away_prompt_minutes' => (int) ($policies->forEmployee($employee, 'attendance.idle', today()->toDateString(), ['away_prompt_minutes' => 10])['away_prompt_minutes'] ?? 10),
                // No keyboard/mouse this long counts as idle on the desktop app (shorter pauses are working time).
                'idle_after_minutes' => max(1, (int) ($policies->forEmployee($employee, 'attendance.idle', today()->toDateString(), ['idle_after_minutes' => 5])['idle_after_minutes'] ?? 5)),
            ],
        ]]);
    }
}
