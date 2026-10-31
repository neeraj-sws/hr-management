<?php

namespace Tests\Feature;

use App\Livewire\Billing\InvoiceForm;
use App\Livewire\Billing\InvoiceShow;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingProduct;
use App\Models\BillingTax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** GST tax invoices: company settings, CGST/SGST vs IGST, HSN/SAC, print, payments and edits. */
class BillingInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-10-30 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        Livewire::actingAs($this->admin)->test(\App\Livewire\Billing\Settings::class)
            ->set('company.name', 'Soham Web Solution')->set('company.state', 'Madhya Pradesh')->set('company.gstin', '23abcde1234f1z5')
            ->set('company.bank_name', 'HDFC Bank')->set('company.account_number', '50100012345678')->set('company.ifsc', 'hdfc0001234')
            ->set('company.due_days', '15')->call('save')->assertHasNoErrors();
    }

    private function customer(string $state): BillingCustomer
    {
        return BillingCustomer::create(['name' => "Client in {$state}", 'billing_state' => $state, 'tax_number' => '27AAACS1234A1Z1', 'is_active' => true]);
    }

    private function invoiceFor(BillingCustomer $customer): BillingInvoice
    {
        $gst = BillingTax::create(['name' => 'GST 18%', 'rate' => 18]);
        $service = BillingProduct::create(['name' => 'Web development', 'type' => 'service', 'sale_price' => 1000, 'tax_id' => $gst->id, 'hsn_sac' => '998314', 'is_active' => true]);
        Livewire::actingAs($this->admin)->test(InvoiceForm::class)
            ->assertSet('dueDate', '2026-11-14')
            ->set('customerId', $customer->id)->assertSet('placeOfSupply', $customer->billing_state)
            ->set('items.0.product_id', $service->id)->assertSet('items.0.hsn_sac', '998314')
            ->set('items.0.quantity', '10')->call('save')->assertHasNoErrors();

        return BillingInvoice::with('items.tax', 'customer')->latest('id')->firstOrFail();
    }

    public function test_settings_are_validated_and_upper_cased(): void
    {
        $company = app(\App\Services\BillingService::class)->company();
        $this->assertSame(['23ABCDE1234F1Z5', 'HDFC0001234', '15'], [$company['gstin'], $company['ifsc'], $company['due_days']]);
        Livewire::actingAs($this->admin)->test(\App\Livewire\Billing\Settings::class)->set('company.gstin', 'WRONG')->call('save')->assertHasErrors('company.gstin');
    }

    public function test_same_state_invoice_splits_cgst_and_sgst_and_prints_as_a_tax_invoice(): void
    {
        $invoice = $this->invoiceFor($this->customer('Madhya Pradesh'));
        $this->assertSame(['Madhya Pradesh', '998314', 11800.0], [$invoice->place_of_supply, $invoice->items->first()->hsn_sac, $invoice->total()]);
        $split = app(\App\Services\BillingService::class)->taxSplit($invoice);
        $this->assertEquals([['rate' => 18.0, 'taxable' => 10000.0, 'cgst' => 900.0, 'sgst' => 900.0, 'igst' => 0.0, 'total_tax' => 1800.0]], $split);

        $this->actingAs($this->admin)->get(route('billing.invoices.print', $invoice))->assertOk()
            ->assertSee('Tax Invoice')->assertSee('Soham Web Solution')->assertSee('23ABCDE1234F1Z5')->assertSee('27AAACS1234A1Z1')
            ->assertSee('Intra-state supply: CGST + SGST')->assertSee('998314')->assertSee('Eleven Thousand Eight Hundred Rupees Only')
            ->assertSee('HDFC0001234')->assertSee('SWS/2026-27/00001');
        $this->get(route('billing.invoices.show', $invoice))->assertOk()->assertSee('Print / PDF')->assertSee('CGST');
    }

    public function test_other_state_invoice_uses_igst(): void
    {
        $invoice = $this->invoiceFor($this->customer('Maharashtra'));
        $split = app(\App\Services\BillingService::class)->taxSplit($invoice);
        $this->assertEquals([0.0, 0.0, 1800.0], [$split[0]['cgst'], $split[0]['sgst'], $split[0]['igst']]);
        $this->actingAs($this->admin)->get(route('billing.invoices.print', $invoice))->assertSee('Inter-state supply: IGST');
    }

    public function test_states_match_by_name_short_form_or_gst_code_and_gstin_labels_are_cleaned(): void
    {
        $billing = app(\App\Services\BillingService::class); // company state: Madhya Pradesh
        $this->assertFalse($billing->isInterState('MP'), 'MP is Madhya Pradesh: CGST + SGST');
        $this->assertFalse($billing->isInterState('23'));
        $this->assertTrue($billing->isInterState('DL'), 'Delhi: IGST');
        $this->assertSame(['07', 'Delhi (07)', '07AADCD9227G1ZN'], [\App\Services\BillingService::stateCode('New Delhi'), \App\Services\BillingService::stateLabel('DL'), \App\Services\BillingService::cleanGstin('GSTIN: 07AADCD9227G1ZN')]);
    }

    public function test_payments_cannot_exceed_due_can_be_removed_and_edits_cannot_drop_below_received(): void
    {
        $invoice = $this->invoiceFor($this->customer('Madhya Pradesh'));
        Livewire::actingAs($this->admin)->test(InvoiceShow::class, ['invoice' => $invoice])
            ->call('openPayment')->set('payAmount', '20000')->call('recordPayment')->assertHasErrors('payAmount')
            ->set('payAmount', '5000')->call('recordPayment')->assertHasNoErrors();
        $this->assertSame('Partially Paid', $invoice->fresh()->status);

        // Lowering the invoice below what was received is refused.
        Livewire::actingAs($this->admin)->test(InvoiceForm::class, ['invoice' => $invoice->fresh()])
            ->set('items.0.quantity', '2')->call('save')->assertHasErrors('items');
        $this->assertEquals(11800.0, $invoice->fresh()->load('items.tax')->total());

        $payment = $invoice->payments()->firstOrFail();
        Livewire::actingAs($this->admin)->test(InvoiceShow::class, ['invoice' => $invoice])->call('deletePayment', $payment->id);
        $this->assertSame(['Unpaid', 0], [$invoice->fresh()->status, $invoice->payments()->count()]);
        $this->assertTrue(\App\Models\AuditLog::where('action', 'billing.payment_deleted')->exists());
    }

    public function test_moving_the_issue_date_into_another_financial_year_renumbers_the_invoice(): void
    {
        $invoice = $this->invoiceFor($this->customer('Madhya Pradesh'));
        Livewire::actingAs($this->admin)->test(InvoiceForm::class, ['invoice' => $invoice])->set('issueDate', '2027-04-02')->set('dueDate', '2027-04-10')->call('save')->assertHasNoErrors();
        $this->assertSame('SWS/2027-28/00001', $invoice->fresh()->number());
    }
}
