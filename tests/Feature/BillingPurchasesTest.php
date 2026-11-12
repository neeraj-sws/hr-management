<?php

namespace Tests\Feature;

use App\Livewire\Billing\BillForm;
use App\Livewire\Billing\BillShow;
use App\Livewire\Billing\Bills;
use App\Livewire\Billing\CustomerShow;
use App\Livewire\Billing\Estimates;
use App\Livewire\Billing\Expenses;
use App\Livewire\Billing\Transfers;
use App\Livewire\Billing\Vendors;
use App\Models\BillingBankAccount;
use App\Models\BillingBill;
use App\Models\BillingCategory;
use App\Models\BillingCustomer;
use App\Models\BillingEstimate;
use App\Models\BillingExpense;
use App\Models\BillingInvoice;
use App\Models\BillingInvoiceItem;
use App\Models\BillingInvoicePayment;
use App\Models\BillingTax;
use App\Models\BillingVendor;
use App\Models\User;
use App\Services\InvoiceActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Purchases (suppliers, bills, expenses, debit notes), transfers, statements and proposal actions — like the old CMS. */
class BillingPurchasesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private BillingBankAccount $hdfc;
    private BillingBankAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-05 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->hdfc = BillingBankAccount::create(['holder_name' => 'SWS', 'bank_name' => 'HDFC Bank', 'opening_balance' => 10000]);
        $this->cash = BillingBankAccount::where('bank_name', 'Cash')->firstOrFail();
    }

    public function test_supplier_bill_payment_debit_note_and_balances(): void
    {
        Livewire::actingAs($this->admin)->test(Vendors::class)->call('create')->set('name', 'Ravi Jajpure')->set('billingCity', 'Dewas')->call('save')->assertHasNoErrors()->assertSee('Ravi Jajpure')->assertSee('#VEND00001');
        $vendor = BillingVendor::firstOrFail();
        $gst = BillingTax::create(['name' => 'GST 18%', 'rate' => 18]);
        $cat = BillingCategory::create(['name' => 'Computer Hardware Purchases', 'type' => 'expense']);

        Livewire::actingAs($this->admin)->withQueryParams(['vendor' => $vendor->id])->test(BillForm::class)->assertSet('vendorId', $vendor->id)
            ->set('categoryId', $cat->id)->set('refNumber', 'INV-77')->set('items.0.name', 'Keyboard')->set('items.0.quantity', '2')->set('items.0.price', '500')->set('items.0.tax_id', $gst->id)
            ->call('save')->assertHasNoErrors()->assertRedirect();
        $bill = BillingBill::with('items.tax', 'payments', 'debitNotes')->firstOrFail();
        $this->assertSame(['BILL00001', 1180.0, 'Draft'], [$bill->number(), $bill->total(), $bill->status]);

        $show = Livewire::actingAs($this->admin)->test(BillShow::class, ['bill' => $bill])->assertSee('Keyboard')->assertSee('Payment Summary');
        $show->call('markReceived');
        $show->call('openPayment')->assertSet('payAmount', '1180')->set('payAmount', '5000')->call('recordPayment')->assertHasErrors('payAmount');
        $show->set('payAmount', '1000')->set('payAccountId', $this->hdfc->id)->set('payReceipt', UploadedFile::fake()->image('slip.jpg'))->call('recordPayment')->assertHasNoErrors();
        $show->call('openDebit')->assertSet('debitAmount', '180')->set('debitDescription', 'Returned one cable')->call('saveDebit')->assertHasNoErrors()->assertSee('Returned one cable');
        $this->assertSame('Paid', $bill->fresh()->status);
        $this->assertSame(9000.0, $this->hdfc->balance()); // 10000 opening − 1000 paid to the supplier
        $p = $bill->payments()->firstOrFail();
        $this->actingAs($this->admin)->get(route('billing.receipt-file', ['bill-payment', $p->id]))->assertOk();

        Livewire::actingAs($this->admin)->test(Bills::class)->assertSee('BILL00001')->call('delete', $bill->id)->assertDispatched('toast');
        $this->assertNotNull($bill->fresh()); // has a payment: kept
        Livewire::actingAs($this->admin)->test(Vendors::class)->call('delete', $vendor->id);
        $this->assertNotNull($vendor->fresh()); // has a bill: kept
        $this->actingAs($this->admin)->get(route('billing.vendors.export'))->assertOk();
    }

    public function test_expenses_and_transfers_move_account_balances(): void
    {
        $cat = BillingCategory::create(['name' => 'Tea Expenses', 'type' => 'expense']);
        Livewire::actingAs($this->admin)->test(Expenses::class)->call('create')->assertSet('categoryId', $cat->id)
            ->set('amount', '250')->set('accountId', $this->cash->id)->set('description', 'Tea for October')->call('save')->assertHasNoErrors()->assertSee('Tea for October');
        $this->assertSame(1, BillingExpense::count());

        Livewire::actingAs($this->admin)->test(Transfers::class)->call('create')->set('fromAccountId', $this->hdfc->id)->set('toAccountId', $this->hdfc->id)->set('amount', '2000')
            ->call('save')->assertHasErrors('toAccountId')->set('toAccountId', $this->cash->id)->call('save')->assertHasNoErrors()->assertSee('HDFC Bank');
        $this->assertSame([8000.0, 1750.0], [$this->hdfc->balance(), $this->cash->balance()]); // cash: 0 − 250 + 2000
        $this->actingAs($this->admin)->get(route('billing.bank-accounts'))->assertOk()->assertSee('8,000.00');
    }

    public function test_customer_statement_proposal_actions_and_customer_delete(): void
    {
        $customer = BillingCustomer::create(['name' => 'ELCINA', 'billing_state' => 'Delhi', 'is_active' => true]);
        $old = BillingInvoice::create(['number' => 1, 'financial_year' => '2026-27', 'customer_id' => $customer->id, 'issue_date' => '2026-04-10', 'status' => 'Sent']);
        BillingInvoiceItem::create(['invoice_id' => $old->id, 'name' => 'April work', 'quantity' => 1, 'price' => 1000]);
        $new = BillingInvoice::create(['number' => 2, 'financial_year' => '2026-27', 'customer_id' => $customer->id, 'issue_date' => '2026-10-10', 'status' => 'Sent']);
        BillingInvoiceItem::create(['invoice_id' => $new->id, 'name' => 'October work', 'quantity' => 1, 'price' => 2000]);
        BillingInvoicePayment::create(['invoice_id' => $new->id, 'date' => '2026-10-20', 'amount' => 500, 'method' => 'UPI']);
        $this->actingAs($this->admin)->get(route('billing.customers.statement', ['customer' => $customer, 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()
            ->assertSee('Account Statement')->assertSee('Opening balance')->assertSee('1,000.00')->assertSee('Invoice SWS/2026-27/00002')->assertSee('2,500.00'); // closing = 1000 + 2000 − 500

        $est = BillingEstimate::create(['number' => 1, 'financial_year' => '2026-27', 'customer_id' => $customer->id, 'issue_date' => '2026-11-01', 'status' => 'Sent']);
        $this->assertSame('#PROP00001', $est->number()); // like the CMS: one running number, no year
        $this->actingAs($this->admin)->get(route('billing.estimates.export'))->assertOk();
        $url = app(InvoiceActions::class)->estimateUrl($est);
        auth()->logout();
        $this->get($url)->assertOk()->assertSee('ELCINA');
        Livewire::actingAs($this->admin)->test(Estimates::class)->call('duplicate', $est->id)->assertRedirect();
        $this->assertSame(2, BillingEstimate::count());
        Livewire::actingAs($this->admin)->test(CustomerShow::class, ['customer' => $customer])->assertSee('Proposal')->assertSee('Create Proposal')
            ->call('deleteEstimate', BillingEstimate::max('id'));
        $this->assertSame(1, BillingEstimate::count());

        Livewire::actingAs($this->admin)->test(CustomerShow::class, ['customer' => $customer])->call('deleteCustomer')->assertDispatched('toast');
        $this->assertNotNull($customer->fresh()); // has invoices: kept
        $empty = BillingCustomer::create(['name' => 'Nobody', 'is_active' => true]);
        Livewire::actingAs($this->admin)->test(CustomerShow::class, ['customer' => $empty])->call('deleteCustomer')->assertRedirect(route('billing.customers'));
        $this->assertNull($empty->fresh());
    }

    public function test_cms_like_create_pages(): void
    {
        $customer = BillingCustomer::create(['name' => 'Saletify', 'billing_name' => 'Saletify Marketing Pvt Ltd', 'billing_phone' => '8530666677', 'billing_address' => '305, Kumar Primus, Hadapsar',
            'billing_city' => 'Pune', 'billing_state' => 'MH', 'billing_country' => 'India', 'billing_zip' => '411013', 'is_active' => true]);
        BillingCategory::create(['name' => 'Web Development Service', 'type' => 'income']);
        Livewire::actingAs($this->admin)->withQueryParams(['customer' => $customer->id])->test(\App\Livewire\Billing\InvoiceForm::class)
            ->assertSee(['Invoice Create', 'Bill to', 'Saletify Marketing Pvt Ltd', '411013', 'Ship to', '#SWS/2026-27/00001', 'Web Development Service', 'Product &amp; Services', 'Total Amount (Rs)'], false)
            ->assertSet('placeOfSupply', 'MH');
        Livewire::actingAs($this->admin)->withQueryParams(['customer' => $customer->id])->test(\App\Livewire\Billing\EstimateForm::class)
            ->assertSee(['Proposal Create', 'Bill to', 'Proposal Number', 'Category', 'Product &amp; Services'], false);
    }
}
