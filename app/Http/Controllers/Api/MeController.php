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
            // The same badges as the website header (Master Admin / HR / Manager / Team lead / Employee / Trainee).
            'badges' => \App\Support\PersonBadges::for($user, $employee),
            // Profile photo set on the website (or in the app): the apps load it from /api/me/photo with their token.
            // ?v changes when the photo changes, so a new photo shows at once instead of a cached one.
            // People reporting to them (the "Team" button in the app's header).
            'team_size' => $employee ? $employee->directReports()->where('is_active', true)->count() : 0,
            'photo_url' => $employee?->photo_path ? url('/api/me/photo').'?v='.substr(md5($employee->photo_path), 0, 8) : null,
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            'employee' => $employee ? (new EmployeeResource($employee))->resolve() : null,
            'today' => $session ? array_merge((new AttendanceSessionResource($session))->resolve(), []) : null,
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
                // Field / mobile work: idle is not taken off, so the desktop app does not ask "Are you sitting idle?".
                'work_type' => $employee && ($policies->forEmployee($employee, \App\Models\AttendanceSession::WORK_TYPE_POLICY, today()->toDateString(), [])['field'] ?? false) ? 'field' : 'desk',
                'idle_after_minutes' => max(1, (int) ($policies->forEmployee($employee, 'attendance.idle', today()->toDateString(), ['idle_after_minutes' => 5])['idle_after_minutes'] ?? 5)),
                // When today's shift ends (desktop: "your shift ended" popup) and automatic check-out after it.
                'shift_end' => $employee ? $attendance->expectedEnd($employee, today()->toDateString())?->toIso8601String() : null,
                'auto_checkout' => $attendance->autoCheckoutPolicy($employee),
            ],
        ]]);
    }

    /** POST /api/me/photo: the app sets the profile picture (the same as "Change photo" on the website profile). */
    public function updatePhoto(Request $request, \App\Services\EmployeePhotoService $photos)
    {
        $employee = $request->user()?->employeeProfile;
        abort_unless($employee, 404);
        $request->validate(['photo' => \App\Services\EmployeePhotoService::RULES], ['photo.mimes' => 'Use a JPG, PNG or WebP photo.', 'photo.max' => 'The photo is larger than 3 MB.']);
        $photos->save($employee, $request->file('photo'), $request->user());
        $employee->refresh();

        return response()->json(['message' => 'Profile picture updated.', 'photo_url' => url('/api/me/photo').'?v='.substr(md5((string) $employee->photo_path), 0, 8)]);
    }

    /** DELETE /api/me/photo: back to initials. */
    public function deletePhoto(Request $request, \App\Services\EmployeePhotoService $photos)
    {
        $employee = $request->user()?->employeeProfile;
        abort_unless($employee, 404);
        $photos->remove($employee, $request->user());

        return response()->json(['message' => 'Profile picture removed.', 'photo_url' => null]);
    }

    /** The signed-in person's profile photo, for the desktop and mobile apps (token sign-in, no website session). */
    public function photo(Request $request)
    {
        $employee = $request->user()?->employeeProfile;
        $path = $employee?->photo_path;
        abort_unless($path && \Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=86400']);
    }
}
