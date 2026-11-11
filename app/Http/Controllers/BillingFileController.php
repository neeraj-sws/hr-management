<?php

namespace App\Http\Controllers;

use App\Models\BillingInvoicePayment;
use App\Models\BillingRevenue;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Payment receipts (images / PDFs) attached to invoice payments and revenue, kept on the private disk. */
class BillingFileController extends Controller
{
    public const RULES = ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf'];

    public static function store(UploadedFile $file): string
    {
        return $file->store('billing/receipts', 'local');
    }

    public static function forget(?string $path): void
    {
        if ($path) Storage::disk('local')->delete($path);
    }

    public function receipt(Request $request, string $kind, int $id)
    {
        abort_unless($request->user()?->canAny(['hr.manage', 'payroll.manage']), 403);
        $record = match ($kind) {
            'payment' => BillingInvoicePayment::findOrFail($id),
            'revenue' => BillingRevenue::findOrFail($id),
            'bill-payment' => \App\Models\BillingBillPayment::findOrFail($id),
            'expense' => \App\Models\BillingExpense::findOrFail($id),
            default => abort(404),
        };
        $path = $record->receipt_path;
        abort_unless($path && str_starts_with($path, 'billing/receipts/') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, basename($path), ['Cache-Control' => 'private, max-age=0', 'X-Content-Type-Options' => 'nosniff']);
    }
}
