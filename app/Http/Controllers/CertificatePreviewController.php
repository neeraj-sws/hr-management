<?php

namespace App\Http\Controllers;

use App\Services\HrLetterService;
use Illuminate\Http\Request;

/**
 * The training certificate being prepared, shown inside the certificate form (HR / admin only). What it says is
 * kept in the session by the form, so no personal details travel in the address. Nothing is saved.
 */
class CertificatePreviewController extends Controller
{
    public function __invoke(Request $request, string $token, HrLetterService $letters)
    {
        abort_unless(HrLetterService::canManage($request->user()), 403);
        $fields = $request->session()->get('certificate_preview.'.$token);
        abort_unless(is_array($fields), 404);

        // The employee's company's signature and seal (see EmployeeProfile).
        $pdf = \App\Support\CompanyAccess::withRulesCompany(isset($fields['_company']) ? (int) $fields['_company'] : null, fn () => $letters->certificatePdf($fields));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="certificate-preview.pdf"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
