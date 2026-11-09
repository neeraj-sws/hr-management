<?php

namespace App\Http\Controllers\Api;

use App\Models\Announcement;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\LeaveRequest;
use App\Services\ApprovalInbox;
use App\Services\AttendanceService;
use App\Services\CompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin-only endpoints for the mobile app: dashboard, attendance view, leave view, announcements. */
class AdminController extends ApiController
{
    private function requireAdmin(Request $request)
    {
        return $this->requirePermission($request, 'hr.manage', 'approvals.manage');
    }

    /** GET /api/admin/dashboard — today's summary counts for the admin home screen. */
    public function dashboard(Request $request, AttendanceService $attendance, ApprovalInbox $inbox): JsonResponse
    {
        $user = $this->requireAdmin($request);

        $employees = AttendanceEmployee::where('is_active', true)->get(['id']);
        $ids = $employees->pluck('id');

        $sessions = AttendanceSession::whereIn('employee_id', $ids)->whereDate('work_date', today())->get();
        $onLeave = LeaveRequest::whereIn('employee_id', $ids)
            ->where('status', 'approved')
            ->whereDate('starts_on', '<=', today())
            ->whereDate('ends_on', '>=', today())
            ->pluck('employee_id');

        $present = $sessions->count();
        $lateCount = $sessions->filter(fn ($s) => $attendance->isLate($s))->count();

        return response()->json([
            'total_employees' => $employees->count(),
            'present' => $present,
            'absent' => max(0, $employees->count() - $present - $onLeave->count()),
            'on_leave' => $onLeave->count(),
            'late' => $lateCount,
            'working' => $sessions->where('status', 'working')->count(),
            'on_break' => $sessions->where('status', 'on_break')->count(),
            'checked_out' => $sessions->where('status', 'checked_out')->count(),
            'pending_approvals' => $inbox->pendingCount($user),
            'pending_leaves' => LeaveRequest::whereIn('employee_id', $ids)->where('status', 'pending')->count(),
        ]);
    }

    /** GET /api/admin/attendance?date=YYYY-MM-DD — all employees' attendance for a date. */
    public function attendance(Request $request, AttendanceService $attendance): JsonResponse
    {
        $this->requireAdmin($request);

        $date = $this->dayFilter($request) ?? today()->toDateString();
        $employeeId = (int) $request->query('employee_id', 0);

        $employees = AttendanceEmployee::where('is_active', true)
            ->with('department')
            ->when($employeeId, fn ($q) => $q->where('id', $employeeId))
            ->inCodeOrder()
            ->get();

        $ids = $employees->pluck('id');
        $sessions = AttendanceSession::whereIn('employee_id', $ids)
            ->whereDate('work_date', $date)
            ->get()->keyBy('employee_id');
        $onLeave = LeaveRequest::whereIn('employee_id', $ids)
            ->where('status', 'approved')
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->pluck('employee_id');

        return response()->json([
            'date' => $date,
            'data' => $employees->map(function ($e) use ($sessions, $onLeave, $attendance) {
                $s = $sessions->get($e->id);
                $state = $s ? $s->status : ($onLeave->contains($e->id) ? 'on_leave' : 'not_in');

                return [
                    'id' => $e->id,
                    'name' => $e->name,
                    'code' => $e->employee_code,
                    'designation' => $e->designation,
                    'department' => $e->department?->name,
                    'state' => $state,
                    'check_in' => $s?->check_in?->toIso8601String(),
                    'check_out' => $s?->check_out?->toIso8601String(),
                    'worked_seconds' => $s ? $attendance->workedSeconds($s) : 0,
                    'late' => $s ? $attendance->isLate($s) : false,
                    'closed_automatically' => (bool) $s?->closed_automatically,
                ];
            })->values(),
        ]);
    }

    /**
     * GET /api/admin/leave — all employees' leave requests.
     * ?status=pending|approved|rejected|cancelled  (default: pending)
     * ?employee_id=N
     * ?month=YYYY-MM
     */
    public function leaveRequests(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $status = in_array($request->query('status'), ['pending', 'approved', 'rejected', 'cancelled'], true)
            ? $request->query('status') : null;
        $employeeId = (int) $request->query('employee_id', 0);
        $month = $this->monthRange($request);

        $ids = AttendanceEmployee::where('is_active', true)->pluck('id');

        $leaves = LeaveRequest::with(['employee', 'leaveType'])
            ->whereIn('employee_id', $ids)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when(! $status && ! $month, fn ($q) => $q->where('status', 'pending'))
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->when($month, fn ($q) => $q->whereDate('starts_on', '<=', $month[1])->whereDate('ends_on', '>=', $month[0]))
            ->latest('starts_on')
            ->limit(150)
            ->get();

        return response()->json(['data' => $leaves->map(fn ($l) => [
            'id' => $l->id,
            'employee' => ['id' => $l->employee->id, 'name' => $l->employee->name, 'code' => $l->employee->employee_code],
            'leave_type' => $l->leaveType?->name ?? ($l->other_type ?? '—'),
            'starts_on' => $l->starts_on->toDateString(),
            'ends_on' => $l->ends_on->toDateString(),
            'duration' => $l->duration,
            'reason' => $l->reason,
            'status' => $l->status,
            'applied_on' => $l->created_at->toDateString(),
        ])->values()]);
    }

    /** POST /api/announcements — create or update an announcement (admin/HR only). */
    public function createAnnouncement(Request $request, CompanyService $company): JsonResponse
    {
        $user = $this->requirePermission($request, 'announcements.manage', 'hr.manage');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'is_pinned' => ['boolean'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $announcement = $company->saveAnnouncement([
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => 'all',
            'department_id' => null,
            'team_id' => null,
            'employee_ids' => [],
            'excluded_department_ids' => [],
            'excluded_team_ids' => [],
            'is_pinned' => $data['is_pinned'] ?? false,
            'expires_on' => $data['expires_on'] ?? null,
            'company_id' => null,
        ], $user);

        return response()->json([
            'data' => ['id' => $announcement->id, 'title' => $announcement->title],
            'message' => 'Announcement posted.',
        ], 201);
    }

    /** DELETE /api/announcements/{announcement} — delete an announcement (admin/HR only). */
    public function deleteAnnouncement(Request $request, Announcement $announcement): JsonResponse
    {
        $this->requirePermission($request, 'announcements.manage', 'hr.manage');
        $announcement->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
