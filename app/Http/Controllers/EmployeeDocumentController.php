<?php

namespace App\Http\Controllers;

use App\Models\EmployeeDocument;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocumentController extends Controller
{
    public function download(EmployeeDocument $document, AuditLogger $audit): StreamedResponse
    {
        $this->authorizeDocument($document);
        $audit->record('employee.document_accessed', $document, ['category' => $document->category]);

        return Storage::disk('local')->download($document->storage_path, $document->original_name);
    }

    /** Opens the document in the browser (PDFs and images show inline; other files still download). */
    public function view(EmployeeDocument $document, AuditLogger $audit): StreamedResponse
    {
        $this->authorizeDocument($document);
        $audit->record('employee.document_viewed', $document, ['category' => $document->category]);

        return Storage::disk('local')->response($document->storage_path, $document->original_name, [], 'inline');
    }

    /** The employee, their manager, and HR / administrators may open a document. */
    private function authorizeDocument(EmployeeDocument $document): void
    {
        $user = auth()->user();
        $employee = $document->employee;
        $allowed = $user->canAny(['lifecycle.manage', 'hr.manage', 'employees.manage']) || \App\Support\Access::isAdmin($user)
            || $employee?->user_id === $user->id || $employee?->manager?->user_id === $user->id;
        abort_unless($allowed, 403);
        abort_unless(Storage::disk('local')->exists($document->storage_path), 404, 'The file is missing from storage.');
    }
}
