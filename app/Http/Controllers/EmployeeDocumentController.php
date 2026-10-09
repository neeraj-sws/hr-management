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
        $user=auth()->user();
        $employee=$document->employee;
        $allowed=$user->can('lifecycle.manage')||$user->can('hr.manage')||$employee?->user_id===$user->id||$employee?->manager?->user_id===$user->id;
        abort_unless($allowed,403);
        abort_unless(Storage::disk('local')->exists($document->storage_path),404);
        $audit->record('employee.document_accessed',$document,['category'=>$document->category]);
        return Storage::disk('local')->download($document->storage_path,$document->original_name);
    }
}
