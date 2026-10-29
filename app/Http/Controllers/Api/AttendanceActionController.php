<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\AttendanceSessionResource;
use App\Models\AttendanceSession;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Attendance actions for signed-in employees (mobile app / web). Device agents use /attendance/sync. */
class AttendanceActionController extends ApiController
{
    public function __construct(private readonly AttendanceService $attendance) {}

    public function today(Request $request): JsonResponse
    {
        return $this->respond($this->attendance->today($this->apiEmployee($request)));
    }

    public function checkIn(Request $request): JsonResponse
    {
        $this->trackDesktop($request);
        return $this->respond($this->attendance->checkIn($this->apiEmployee($request), $this->source($request), null, $this->location($request), $request->file('photo')), 201);
    }

    public function startBreak(Request $request): JsonResponse
    {
        $this->trackDesktop($request);
        return $this->respond($this->attendance->startBreak($this->apiEmployee($request), $this->source($request)));
    }

    public function endBreak(Request $request): JsonResponse
    {
        $this->trackDesktop($request);
        return $this->respond($this->attendance->endBreak($this->apiEmployee($request), $this->source($request)));
    }

    public function checkOut(Request $request): JsonResponse
    {
        $this->trackDesktop($request);
        return $this->respond($this->attendance->checkOut($this->apiEmployee($request), $this->source($request), null, $this->location($request), $request->file('photo')));
    }

    /** Desktop: nobody used the computer after the shift; check out at the last use. */
    public function autoCheckOut(Request $request): JsonResponse
    {
        $this->trackDesktop($request, true);
        $data = $request->validate(['last_activity_at' => ['required', 'date']]);

        return $this->respond($this->attendance->autoCheckOut($this->apiEmployee($request), Carbon::parse($data['last_activity_at'])));
    }

    /** Desktop: the employee confirmed that time away from the computer was a break. */
    public function away(Request $request): JsonResponse
    {
        $this->trackDesktop($request, true);
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after:from'], 'reason' => ['required', 'in:idle,locked,asleep'], 'as' => ['nullable', 'in:break,work'], 'note' => ['nullable', 'string', 'max:200']]);
        $from = Carbon::parse($data['from']);
        abort_if($from->lt(now()->subHours(24)), 422, 'Away periods older than 24 hours cannot be recorded; ask for an attendance correction instead.');
        // "I was working" (e.g. a meeting): kept as working time, not counted as idle.
        if (($data['as'] ?? 'break') === 'work') return $this->respond($this->attendance->recordAwayAsWork($this->apiEmployee($request), $from, Carbon::parse($data['to']), $data['reason'], $data['note'] ?? null));

        return $this->respond($this->attendance->recordAway($this->apiEmployee($request), $from, Carbon::parse($data['to']), $data['reason'], 'desktop'));
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $this->trackDesktop($request, true);
        $data = $request->validate(['screen_active_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'], 'screen_idle_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'], 'screen_meeting_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'], 'screen_state' => ['nullable', 'in:active,idle,meeting']]);

        return $this->respond($this->attendance->heartbeat($this->apiEmployee($request), (int) ($data['screen_active_seconds'] ?? 0), (int) ($data['screen_idle_seconds'] ?? 0), (int) ($data['screen_meeting_seconds'] ?? 0), $data['screen_state'] ?? null));
    }

    public function history(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($data['to'] ?? today());
        abort_if($from->diffInDays($to) > 92, 422, 'History range may not exceed 92 days.');

        return response()->json(['data' => $this->attendance->days($employee, $from, $to)->map(fn ($day) => [
            'date' => $day['date'],
            'status' => $day['status'],
            // Absent with a leave request still waiting for a decision: the app shows "Leave pending".
            'leave_pending' => (bool) ($day['leave_pending'] ?? false), 'label' => $day['label'] ?? null,
            'session' => $day['session'] ? (new AttendanceSessionResource($day['session']))->resolve() : null,
            'pay_note' => $day['session'] ? $this->payNote($employee, $day['session']) : null,
        ])->values()]);
    }

    /** Same words as the website calendar: a late day is paid by the hours, at most one full working day. */
    private function payNote($employee, AttendanceSession $session): ?string
    {
        $pay = app(\App\Services\HrWorkflowService::class)->lateCheckInPay($employee, $session);
        if (! $pay) return null;
        $hm = fn (int $s) => \App\Support\Duration::hm($s);
        if ($pay['seconds'] < $pay['standard']) return 'paid for '.$hm($pay['seconds']).' of '.$hm($pay['standard']);
        $extra = $pay['seconds'] - $pay['standard'];

        return 'paid '.$hm($pay['standard']).' (max)'.($extra >= 60 ? ' · +'.$hm($extra).' extra, not paid' : '');
    }

    /** Position sent by the phone at check-in/out (validated in AttendanceService::locate). */
    private function location(Request $request): ?array
    {
        $request->validate(['latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'], 'accuracy' => ['nullable', 'numeric', 'min:0']]);

        return $request->filled(['latitude', 'longitude']) ? $request->only(['latitude', 'longitude', 'accuracy']) : null;
    }

    /**
     * The desktop app signs in with the employee's own account (one token per computer), so its computer is
     * listed under Attendance > Devices from that token: name, Windows user and app version (sent as headers),
     * last seen. Revoking it there deletes the token, which signs that computer out.
     */
    private function trackDesktop(Request $request, bool $desktopOnly = false): void
    {
        if (! $desktopOnly && $request->input('source') !== 'desktop') return;
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof \Laravel\Sanctum\PersonalAccessToken || ! $token->getKey()) return;
        // Listing the computer must never get in the way of the attendance action itself.
        rescue(fn () => $this->rememberDesktop($request, $token), null, true);
    }

    private function rememberDesktop(Request $request, \Laravel\Sanctum\PersonalAccessToken $token): void
    {
        $employee = $this->apiEmployee($request);
        $device = \App\Models\AttendanceDevice::firstOrNew(['device_id' => 'desktop-'.$token->id]);
        if ($device->exists && $device->last_seen_at?->gt(now()->subMinute()) && $device->employee_id === $employee->id) return; // at most one write a minute
        $device->fill([
            'employee_id' => $employee->id,
            'computer_name' => mb_substr((string) ($request->header('X-Computer-Name') ?: $device->computer_name ?: $token->name), 0, 255),
            'windows_user' => $request->header('X-Windows-User') ? mb_substr($request->header('X-Windows-User'), 0, 255) : $device->windows_user,
            'app_version' => $request->header('X-App-Version') ? mb_substr($request->header('X-App-Version'), 0, 50) : $device->app_version,
            'last_seen_at' => now(),
            'is_active' => true,
        ]);
        $device->forceFill(['personal_access_token_id' => $token->id])->save();
    }

    private function source(Request $request): string
    {
        return in_array($request->input('source'), ['mobile', 'desktop', 'web'], true) ? $request->input('source') : 'mobile';
    }

    private function respond(?AttendanceSession $session, int $status = 200): JsonResponse
    {
        // break_started_at: when the break now running began, so the apps can show "on break for …" live.
        $breakStart = $session && $session->status === 'on_break' ? $session->events()->where('event_type', 'break_start')->latest('event_time')->value('event_time') : null;

        return response()->json(['data' => $session ? array_merge((new AttendanceSessionResource($session->load('events')))->resolve(), [
            'break_started_at' => $breakStart ? \Illuminate\Support\Carbon::parse($breakStart)->toIso8601String() : null,
        ]) : null], $status);
    }
}
