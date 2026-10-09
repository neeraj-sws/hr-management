<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\ExpenseClaim;
use App\Models\Screenshot;
use App\Services\AuditLogger;
use App\Services\ExpenseService;
use App\Services\ScreenCaptureService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Private uploads (expense receipts, CVs) are only streamed to people allowed to see them. */
class HrFileController extends Controller
{
    public function receipt(ExpenseClaim $claim, ExpenseService $expenses, AuditLogger $audit): StreamedResponse
    {
        abort_unless($claim->receipt_path && $expenses->canView(auth()->user(), $claim), 403);
        abort_unless(Storage::disk('local')->exists($claim->receipt_path), 404);
        $audit->record('expense.receipt_viewed', $claim);

        return Storage::disk('local')->response($claim->receipt_path, $claim->receipt_name ?? 'receipt');
    }

    public function screenshot(Screenshot $screenshot, ScreenCaptureService $captures, AuditLogger $audit): StreamedResponse
    {
        abort_unless($captures->canView(auth()->user(), $screenshot), 403);
        abort_if($captures->isExpired($screenshot) || ! Storage::disk('local')->exists($screenshot->path), 410, 'This capture has expired.');
        $download = request()->boolean('download');
        $audit->record($download ? 'screenshot.downloaded' : 'screenshot.viewed', $screenshot, ['employee_id' => $screenshot->employee_id]);
        $name = 'screenshot-'.$screenshot->employee_id.'-'.$screenshot->captured_at->format('Ymd-His').'.'.pathinfo($screenshot->path, PATHINFO_EXTENSION);

        return $download ? Storage::disk('local')->download($screenshot->path, $name) : Storage::disk('local')->response($screenshot->path, $name, ['Cache-Control' => 'private, no-store']);
    }

    /** Phone download link: the signature binds the document to one user and expires in minutes; access is re-checked. */
    public function signedDocument(\App\Models\EmployeeDocument $document, AuditLogger $audit): StreamedResponse
    {
        $user = \App\Models\User::where('is_active', true)->find((int) request()->query('user'));
        $employee = $document->employee;
        abort_unless($user && ($employee?->user_id === $user->id || $user->can('lifecycle.manage') || $employee?->manager?->user_id === $user->id), 403);
        abort_unless(Storage::disk('local')->exists($document->storage_path), 404);
        $audit->record('employee.document_accessed', $document, ['category' => $document->category, 'via' => 'mobile_link', 'user_id' => $user->id]);

        return Storage::disk('local')->download($document->storage_path, $document->original_name);
    }

    public function resume(Candidate $candidate, AuditLogger $audit): StreamedResponse
    {
        abort_unless($candidate->resume_path && auth()->user()->can('recruitment.manage') || $candidate->interviews()->where('interviewer_id', auth()->id())->exists(), 403);
        abort_unless($candidate->resume_path && Storage::disk('local')->exists($candidate->resume_path), 404);
        $audit->record('candidate.resume_viewed', $candidate);

        return Storage::disk('local')->download($candidate->resume_path, $candidate->resume_name ?? 'resume');
    }
}
