<?php

namespace App\Services;

use App\Models\BillingCustomer;
use App\Models\BillingEstimate;
use App\Models\BillingInvoicePayment;
use App\Models\User;
use App\Notifications\BillingMail;
use App\Support\MailRecipient;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * E-mails at each stage of the client / invoice workflow (like the old CMS e-mail templates):
 * proposal sent, payment received (receipt), account statement and the client-portal welcome.
 * Invoice sent / payment reminder use InvoiceEmail. Every send is audited; a failure is returned, never hidden.
 */
class BillingMailer
{
    public function __construct(private readonly BillingService $billing, private readonly InvoiceActions $actions, private readonly AuditLogger $audit) {}

    private function money(float $v): string
    {
        return '₹'.number_format($v, 2);
    }

    /** Sends and audits; returns an error message, or null when it went. */
    private function deliver(string $to, string $name, BillingMail $mail, ?\Illuminate\Database\Eloquent\Model $subject, string $kind): ?string
    {
        try {
            (new MailRecipient($to, $name))->notifyNow($mail);
        } catch (\Throwable $e) {
            report($e);

            return 'E-mail nahi gaya: '.Str::limit($e->getMessage(), 160).' (Settings → Email check karo).';
        }
        $this->audit->record('billing.email_sent', $subject, ['to' => $to, 'kind' => $kind]);

        return null;
    }

    /** "Proposal Sent": the proposal link; a Draft becomes Sent. */
    public function proposal(BillingEstimate $estimate, string $to, string $message = ''): ?string
    {
        $estimate->loadMissing('customer', 'items.tax');
        if ($estimate->status === 'Draft') $estimate->update(['status' => 'Sent']);
        $company = $this->billing->company();
        $c = $estimate->customer;
        $mail = new BillingMail('Proposal '.$estimate->number().' from '.($company['name'] ?: config('app.name')), 'Hello '.($c?->billing_name ?: $c?->name).',',
            ['Please find our proposal **'.$estimate->number().'** dated '.BillingService::date($estimate->issue_date).' for **'.$this->money($estimate->total()).'**.',
                $estimate->expiry_date ? 'It is valid till '.BillingService::date($estimate->expiry_date).'.' : 'Reply to this e-mail if you would like to go ahead.'],
            'View proposal', $this->actions->estimateUrl($estimate), $message, $company);

        return $this->deliver($to, (string) $c?->name, $mail, $estimate, 'proposal');
    }

    /** "New Invoice Payment": a receipt for money received against an invoice. */
    public function paymentReceipt(BillingInvoicePayment $payment, string $to, string $message = ''): ?string
    {
        $invoice = $payment->invoice()->with(['customer', 'items.tax', 'payments', 'creditNotes'])->firstOrFail();
        $company = $this->billing->company();
        $c = $invoice->customer;
        $due = max(0, $invoice->due());
        $mail = new BillingMail('Payment received: invoice '.$invoice->number(), 'Hello '.($c?->billing_name ?: $c?->name).',',
            ['Thank you! We have received **'.$this->money((float) $payment->amount).'** on '.BillingService::date($payment->date).' against invoice **'.$invoice->number().'**'
                .($payment->reference ? ' (ref. '.$payment->reference.')' : '').'.',
                $due > 0 ? 'Balance still due on this invoice: **'.$this->money($due).'**.' : 'This invoice is now **fully paid**.'],
            'View invoice', $this->actions->publicUrl($invoice), $message, $company);

        return $this->deliver($to, (string) $c?->name, $mail, $invoice, 'payment_receipt');
    }

    /** Signed link to a customer's statement for a period (no sign-in). */
    public function statementUrl(BillingCustomer $customer, string $from, string $to): string
    {
        return URL::signedRoute('billing.customers.statement.public', ['customer' => $customer->id, 'from' => $from, 'to' => $to]);
    }

    /** Account statement for a period, with the closing balance. */
    public function statement(BillingCustomer $customer, string $from, string $to, string $email, float $closing, string $message = ''): ?string
    {
        $company = $this->billing->company();
        $mail = new BillingMail('Account statement '.BillingService::date($from).' – '.BillingService::date($to).' · '.($company['name'] ?: config('app.name')),
            'Hello '.($customer->billing_name ?: $customer->name).',',
            ['Please find your account statement for '.BillingService::date($from).' to '.BillingService::date($to).'.',
                $closing > 0 ? 'Balance due as on '.BillingService::date($to).': **'.$this->money($closing).'**.' : 'Nothing is due — thank you!'],
            'View statement', $this->statementUrl($customer, $from, $to), $message, $company);

        return $this->deliver($email, $customer->name, $mail, $customer, 'statement');
    }

    /** "New Client": the client-portal login — a link to set their own password (no password in the e-mail). */
    public function clientWelcome(User $user): ?string
    {
        $company = $this->billing->company();
        $url = route('password.reset', ['token' => Password::createToken($user), 'email' => $user->email]);
        $mail = new BillingMail('Your client portal at '.($company['name'] ?: config('app.name')), 'Hello '.$user->name.',',
            ['A login has been created for you to see your project timesheets and invoices.', 'Sign-in e-mail: **'.$user->email.'**',
                'Use the button below to set your password (the link works for a limited time; after that use "Forgot password" on the sign-in page).'],
            'Set your password', $url, '', $company);

        return $this->deliver($user->email, $user->name, $mail, $user, 'client_welcome');
    }
}
