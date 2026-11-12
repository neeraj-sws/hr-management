<?php

namespace Tests\Feature;

use App\Livewire\Billing\CustomerShow;
use App\Livewire\Billing\EstimateShow;
use App\Livewire\Billing\InvoiceShow;
use App\Models\BillingCustomer;
use App\Models\BillingEstimate;
use App\Models\BillingEstimateItem;
use App\Models\BillingInvoice;
use App\Models\BillingInvoiceItem;
use App\Models\BillingInvoicePayment;
use App\Models\User;
use App\Notifications\BillingMail;
use App\Services\BillingMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** E-mail at each stage of the client / invoice workflow (like the CMS e-mail templates). */
class BillingEmailsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private BillingCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
        Carbon::setTestNow('2026-11-05 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->customer = BillingCustomer::create(['name' => 'ELCINA', 'email' => 'accounts@elcina.test', 'billing_state' => 'Delhi', 'is_active' => true]);
    }

    public function test_payment_receipt_is_emailed_when_recorded_and_on_demand(): void
    {
        $invoice = BillingInvoice::create(['number' => 1, 'financial_year' => '2026-27', 'customer_id' => $this->customer->id, 'issue_date' => '2026-11-01', 'status' => 'Sent']);
        BillingInvoiceItem::create(['invoice_id' => $invoice->id, 'name' => 'Website', 'quantity' => 1, 'price' => 1000]);

        $page = Livewire::actingAs($this->admin)->test(InvoiceShow::class, ['invoice' => $invoice]);
        $page->call('openPayment')->assertSet('payEmail', true)->set('payAmount', '400')->call('recordPayment')->assertHasNoErrors();
        Notification::assertSentTimes(BillingMail::class, 1);
        $payment = BillingInvoicePayment::firstOrFail();
        $page->call('openEmail', 'payment', $payment->id)->assertSet('emailTo', 'accounts@elcina.test')->call('sendEmail')->assertHasNoErrors()->assertSee('Receipt e-mailed');
        Notification::assertSentTimes(BillingMail::class, 2);

        // Unticked: no e-mail.
        $page->call('openPayment')->set('payEmail', false)->set('payAmount', '100')->call('recordPayment');
        Notification::assertSentTimes(BillingMail::class, 2);
    }

    public function test_proposal_and_statement_are_emailed_with_working_links(): void
    {
        $est = BillingEstimate::create(['number' => 1, 'financial_year' => '2026-27', 'customer_id' => $this->customer->id, 'issue_date' => '2026-11-01', 'status' => 'Draft']);
        BillingEstimateItem::create(['estimate_id' => $est->id, 'name' => 'App', 'quantity' => 1, 'price' => 5000]);
        Livewire::actingAs($this->admin)->test(EstimateShow::class, ['estimate' => $est])->assertSee('Send proposal')
            ->call('openEmail')->assertSet('emailTo', 'accounts@elcina.test')->call('sendEmail')->assertHasNoErrors();
        $this->assertSame('Sent', $est->fresh()->status);
        Notification::assertSentTimes(BillingMail::class, 1);

        $invoice = BillingInvoice::create(['number' => 2, 'financial_year' => '2026-27', 'customer_id' => $this->customer->id, 'issue_date' => '2026-10-01', 'status' => 'Sent']);
        BillingInvoiceItem::create(['invoice_id' => $invoice->id, 'name' => 'Work', 'quantity' => 1, 'price' => 3000]);
        Livewire::actingAs($this->admin)->test(CustomerShow::class, ['customer' => $this->customer])->assertSee('Email statement')
            ->call('openEmail')->set('emailTo', 'bad')->call('sendEmail')->assertHasErrors('emailTo')
            ->set('emailTo', 'accounts@elcina.test')->call('sendEmail')->assertHasNoErrors();
        Notification::assertSentTimes(BillingMail::class, 2);

        $url = app(BillingMailer::class)->statementUrl($this->customer, '2026-10-01', '2026-11-05');
        auth()->logout();
        $this->get($url)->assertOk()->assertSee('Account Statement')->assertSee('3,000.00');
        $this->get(route('billing.customers.statement.public', ['customer' => $this->customer->id, 'from' => '2026-10-01', 'to' => '2026-11-05']))->assertForbidden();
    }

    public function test_client_portal_login_sends_a_set_password_link(): void
    {
        $client = \App\Models\Client::create(['name' => 'Source India', 'email' => 'ops@sourceindia.test', 'is_active' => true]);
        Livewire::actingAs($this->admin)->test(\App\Livewire\Work\Clients::class)
            ->call('openLogin', $client->id)->assertSet('loginSendEmail', true)->set('loginPassword', 'ClientPass123')->call('saveLogin')->assertHasNoErrors();
        Notification::assertSentTimes(BillingMail::class, 1);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'ops@sourceindia.test']);
    }
}
