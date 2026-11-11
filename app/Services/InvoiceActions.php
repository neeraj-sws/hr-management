<?php

namespace App\Services;

use App\Models\BillingInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/** Actions on an invoice from the list and the detail page: share link, copy, delete. */
class InvoiceActions
{
    public function __construct(private BillingService $billing, private AuditLogger $audit) {}

    /** A link the customer can open without signing in (signed, so it cannot be guessed or changed). */
    public function publicUrl(BillingInvoice $invoice): string
    {
        return URL::signedRoute('billing.invoices.public', ['invoice' => $invoice->id]);
    }

    /** A new Draft invoice with the same customer, items, notes and terms; dated today with the next number. */
    public function duplicate(BillingInvoice $source, ?int $userId): BillingInvoice
    {
        $source->loadMissing('items');

        return DB::transaction(function () use ($source, $userId) {
            $today = today();
            $n = $this->billing->nextInvoiceNumber($today);
            $dueDays = $source->due_date ? max(0, (int) $source->issue_date->diffInDays($source->due_date)) : null;
            $copy = BillingInvoice::create([
                'number' => $n['number'], 'financial_year' => $n['financial_year'], 'customer_id' => $source->customer_id,
                'issue_date' => $today->toDateString(), 'due_date' => $dueDays !== null ? $today->copy()->addDays($dueDays)->toDateString() : null,
                'ref_number' => null, 'notes' => $source->notes, 'terms' => $source->terms, 'status' => 'Draft', 'send_date' => null,
                'place_of_supply' => $source->place_of_supply, 'project_id' => $source->project_id, 'created_by' => $userId,
            ]);
            foreach ($source->items as $item) {
                $line = $item->replicate(['invoice_id']);
                $line->invoice_id = $copy->id;
                $line->save();
            }
            $this->audit->record('billing.invoice_created', $copy, ['copied_from' => $source->number()]);

            return $copy;
        });
    }

    /** The customer's link to an estimate (signed, no sign-in). */
    public function estimateUrl(\App\Models\BillingEstimate $estimate): string
    {
        return URL::signedRoute('billing.estimates.public', ['estimate' => $estimate->id]);
    }

    /** A new Draft estimate with the same customer and items, dated today with the next number. */
    public function duplicateEstimate(\App\Models\BillingEstimate $source, ?int $userId): \App\Models\BillingEstimate
    {
        $source->loadMissing('items');

        return DB::transaction(function () use ($source, $userId) {
            $today = today();
            $n = $this->billing->nextEstimateNumber($today);
            $days = $source->expiry_date ? max(0, (int) $source->issue_date->diffInDays($source->expiry_date)) : null;
            $copy = \App\Models\BillingEstimate::create([
                'number' => $n['number'], 'financial_year' => $n['financial_year'], 'customer_id' => $source->customer_id,
                'issue_date' => $today->toDateString(), 'expiry_date' => $days !== null ? $today->copy()->addDays($days)->toDateString() : null,
                'status' => 'Draft', 'notes' => $source->notes, 'terms' => $source->terms, 'place_of_supply' => $source->place_of_supply, 'created_by' => $userId,
            ]);
            foreach ($source->items as $item) {
                $line = $item->replicate(['estimate_id']);
                $line->estimate_id = $copy->id;
                $line->save();
            }
            $this->audit->record('billing.estimate_created', $copy, ['copied_from' => $source->number()]);

            return $copy;
        });
    }

    /** Deletes an estimate (not one already converted to an invoice). */
    public function deleteEstimate(\App\Models\BillingEstimate $estimate): void
    {
        DB::transaction(function () use ($estimate) {
            $this->audit->record('billing.estimate_deleted', $estimate, ['number' => $estimate->number(), 'customer' => $estimate->customer?->name]);
            $estimate->items()->delete();
            $estimate->delete();
        });
    }

    /** Deletes an invoice that has no payments or credit notes (those must be removed first, so money is never lost silently). */
    public function delete(BillingInvoice $invoice): void
    {
        $invoice->loadMissing('items', 'payments', 'creditNotes');
        if ($invoice->payments->isNotEmpty() || $invoice->creditNotes->isNotEmpty()) {
            throw ValidationException::withMessages(['invoice' => 'Is invoice par payment / credit note hai — pehle unhe hatao, phir invoice delete hoga.']);
        }
        DB::transaction(function () use ($invoice) {
            $this->audit->record('billing.invoice_deleted', $invoice, ['number' => $invoice->number(), 'customer' => $invoice->customer?->name, 'amount' => $invoice->total()]);
            \App\Models\TimesheetMonth::where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
            $invoice->items()->delete();
            $invoice->delete();
        });
    }
}
