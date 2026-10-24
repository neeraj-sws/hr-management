<?php

namespace App\Http\Controllers\Api;

use App\Services\EmployeeDocumentService;
use App\Services\EmployeePhotoService;

use App\Models\ApprovalRequest;
use App\Models\AttendanceEmployee;
use App\Models\AttendanceSession;
use App\Models\DailyWorkStatus;
use App\Models\EmployeeDocument;
use App\Models\LeaveRequest;
use App\Models\PerformanceReview;
use App\Services\ApprovalInbox;
use App\Services\AttendanceService;
use App\Services\WorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/** Mobile self-service: my documents, assets and performance; and a manager's team today. */
class SelfServiceController extends ApiController
{
    public function documents(Request $request, EmployeeDocumentService $service): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $user = $this->apiUser($request);

        return response()->json(['data' => EmployeeDocument::where('employee_id', $employee->id)->latest()->get()->map(fn ($d) => [
            'id' => $d->id, 'name' => $d->original_name, 'category' => EmployeeDocumentService::CATEGORIES[$d->category] ?? $d->category, 'category_key' => $d->category,'expires_on' => $d->expires_on?->toDateString(),
            'expired' => (bool) ($d->expires_on && $d->expires_on->lt(today())),
            'can_delete' => $d->uploaded_by === $user->id,
            // Opening a private file from the phone's browser: a link signed for this user, valid 5 minutes.
            'url' => URL::temporarySignedRoute('files.document', now()->addMinutes(5), ['document' => $d->id, 'user' => $user->id]),
            // "View" (PDF and images): a viewer page that shows the file without downloading it.
            'file_type' => $type = \App\Livewire\Lifecycle\Documents::fileKind($d->original_name),
            'view_url' => in_array($type, ['pdf', 'image'], true) ? URL::temporarySignedRoute('files.document.viewer', now()->addMinutes(5), ['document' => $d->id, 'user' => $user->id]) : null,
        ])->values(),
            // The profile photo is kept on the profile (not as a document); the app lists it with the files.
            'photo_url' => $employee->photo_path ? url('/api/me/photo').'?v='.substr(md5($employee->photo_path), 0, 8) : null,
            // What is still missing from the onboarding papers, and which types the employee can upload.
            'checklist' => $service->checklist($employee),
            'upload_types' => collect([EmployeeDocumentService::PHOTO => 'Photo'] + EmployeeDocumentService::employeeCategories())->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }

    /** The employee uploads one of their own documents (or their photo). */
    public function uploadDocument(Request $request, EmployeeDocumentService $service): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $data = $request->validate([
            'category' => ['required', 'string', 'in:'.implode(',', array_keys([EmployeeDocumentService::PHOTO => 1] + EmployeeDocumentService::employeeCategories()))],
            'file' => $request->input('category') === EmployeeDocumentService::PHOTO ? EmployeePhotoService::RULES : EmployeeDocumentService::FILE_RULES,
            'expires_on' => ['nullable', 'date'],
        ], ['file.mimes' => 'Upload a PDF, JPG, PNG or Word file (a photo: JPG, PNG or WebP).', 'file.max' => 'The file is too large (max 10 MB, a photo 3 MB).']);
        $document = $service->store($employee, $data['category'], $request->file('file'), $this->apiUser($request), $data['expires_on'] ?? null);

        return response()->json(['data' => ['id' => $document?->id, 'checklist' => $service->checklist($employee->fresh())], 'message' => 'Uploaded — HR has been told.'], 201);
    }

    public function deleteDocument(Request $request, EmployeeDocument $document, EmployeeDocumentService $service): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        abort_unless($document->employee_id === $employee->id, 404);
        $service->delete($document, $this->apiUser($request));

        return response()->json(['message' => 'Document deleted.']);
    }

    public function assets(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        return response()->json(['data' => $employee->assets()->latest()->get()->map(fn ($a) => [
            'id' => $a->id, 'tag' => $a->asset_tag, 'name' => $a->name, 'status' => $a->status,
            'assigned_on' => $a->assigned_on?->toDateString(), 'returned_on' => $a->returned_on?->toDateString(),
        ])->values()]);
    }

    public function performance(Request $request, WorkService $work): JsonResponse
    {
        $employee = $this->apiEmployee($request);
        $metrics = $work->productivity(collect([$employee]), today()->startOfMonth()->toDateString(), today()->toDateString())->first();

        return response()->json([
            'month' => $metrics ? ['completed_tasks' => $metrics->completed_tasks, 'completed_late' => $metrics->completed_late, 'on_time_rate' => $metrics->on_time_rate, 'open_tasks' => $metrics->open_tasks, 'overdue_tasks' => $metrics->overdue_tasks, 'logged_hours' => $metrics->logged_hours, 'active_hours' => $metrics->active_hours, 'utilization' => $metrics->utilization] : null,
            'reviews' => PerformanceReview::with('reviewer')->where('employee_id', $employee->id)->latest('period_ends_on')->limit(12)->get()->map(fn ($r) => [
                'id' => $r->id, 'period' => $r->period_starts_on?->format('d M Y').' – '.$r->period_ends_on?->format('d M Y'), 'rating' => $r->rating,
                'strengths' => $r->strengths, 'improvements' => $r->improvements, 'reviewer' => $r->reviewer?->name, 'status' => $r->status,
            ])->values(),
        ]);
    }

    /** A manager's direct reports today: status, check-in, late, blockers, plus pending approvals. */
    public function team(Request $request, AttendanceService $attendance, ApprovalInbox $inbox): JsonResponse
    {
        $user = $this->apiUser($request);
        $own = $this->apiEmployee($request);
        $reports = AttendanceEmployee::where('is_active', true)->where('manager_id', $own->id)->inCodeOrder()->get();
        abort_if($reports->isEmpty() && ! $user->canAny(['approvals.manage', 'hr.manage']), 403, 'You do not manage a team.');
        $ids = $reports->pluck('id');
        $sessions = AttendanceSession::with('employee')->whereIn('employee_id', $ids)->whereDate('work_date', today())->get()->keyBy('employee_id');
        $leave = LeaveRequest::whereIn('employee_id', $ids)->where('status', 'approved')->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today())->pluck('employee_id');
        $statuses = DailyWorkStatus::whereIn('employee_id', $ids)->whereDate('work_date', today())->get()->keyBy('employee_id');

        $people = $reports->map(function ($e) use ($sessions, $leave, $statuses, $attendance) {
            $s = $sessions->get($e->id);
            $state = $s ? $s->status : ($leave->contains($e->id) ? 'on_leave' : 'not_in');

            return ['id' => $e->id, 'name' => $e->name, 'designation' => $e->designation, 'state' => $state,
                'check_in' => $s?->check_in?->toIso8601String(), 'late' => $s ? $attendance->isLate($s) : false,
                'worked_seconds' => $s ? $attendance->workedSeconds($s) : 0,
                'daily_status' => (bool) $statuses->get($e->id), 'blockers' => $statuses->get($e->id)?->blockers];
        })->values();

        return response()->json([
            'summary' => ['team' => $people->count(), 'working' => $people->where('state', 'working')->count(), 'on_break' => $people->where('state', 'on_break')->count(),
                'late' => $people->where('late', true)->count(), 'on_leave' => $people->where('state', 'on_leave')->count(), 'not_in' => $people->where('state', 'not_in')->count(),
                'pending_approvals' => $inbox->pendingCount($user)],
            'people' => $people,
        ]);
    }

    /** The employee's own salary: this month so far (as payroll would pay it today) and month-by-month history with every deduction. */
    public function salary(Request $request, \App\Services\SalarySummaryService $salary): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        $user = $this->apiUser($request);
        // "Download payslip" in the app: a link signed for this user, valid 10 minutes (the phone's browser has no sign-in).
        $history = array_map(fn (array $m) => [...$m, 'payslip_url' => ! empty($m['payslip_id'])
            ? \Illuminate\Support\Facades\URL::temporarySignedRoute('files.payslip', now()->addMinutes(10), ['payslip' => $m['payslip_id'], 'user' => $user->id]) : null], $salary->history($employee));

        return response()->json(['current' => $salary->soFar($employee), 'history' => $history]);
    }
}
