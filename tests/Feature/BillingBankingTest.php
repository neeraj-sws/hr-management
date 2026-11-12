<?php

namespace Tests\Feature;

use App\Livewire\Billing\BankAccounts;
use App\Livewire\Billing\Categories;
use App\Livewire\Billing\CreditNotes;
use App\Livewire\Billing\InvoiceShow;
use App\Livewire\Billing\Revenues;
use App\Models\BillingBankAccount;
use App\Models\BillingCategory;
use App\Models\BillingCreditNote;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingInvoiceItem;
use App\Models\BillingInvoicePayment;
use App\Models\BillingRevenue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Banking like the old CMS: accounts, categories, revenue with receipts and filters, payment accounts, credit-note filters. */
class BillingBankingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private BillingCustomer $customer;
    private BillingBankAccount $hdfc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-05 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->customer = BillingCustomer::create(['name' => 'ELCINA', 'billing_state' => 'Delhi', 'is_active' => true]);
        $this->hdfc = BillingBankAccount::create(['holder_name' => 'Soham Web Solution', 'bank_name' => 'HDFC Bank', 'account_number' => '50200063467982', 'opening_balance' => 1000]);
    }

    public function test_accounts_and_categories_are_managed_and_balances_add_up(): void
    {
        $this->assertSame(['Cash'], BillingBankAccount::where('bank_name', 'Cash')->pluck('bank_name')->all()); // created by the migration
        Livewire::actingAs($this->admin)->test(BankAccounts::class)->call('create')->set('bankName', 'ICICI')->set('holderName', 'SWS')->set('openingBalance', '500')
            ->call('save')->assertHasNoErrors()->assertSee('ICICI')->assertSee('HDFC Bank');
        Livewire::actingAs($this->admin)->test(Categories::class)->call('create')->set('name', 'Web Development Service')->call('save')->assertHasNoErrors()->assertSee('Web Development Service');
        $this->assertSame('income', BillingCategory::where('name', 'Web Development Service')->value('type'));

        BillingRevenue::create(['date' => '2026-11-01', 'amount' => 250, 'account_id' => $this->hdfc->id]);
        $this->assertSame(1250.0, $this->hdfc->balance());
        Livewire::actingAs($this->admin)->test(BankAccounts::class)->call('delete', $this->hdfc->id)->assertDispatched('toast');
        $this->assertNotNull($this->hdfc->fresh()); // has revenue: kept
    }

    public function test_revenue_like_the_cms_with_account_category_receipt_filters_and_delete(): void
    {
        $category = BillingCategory::create(['name' => 'Web Development Service', 'type' => 'income']);
        $page = Livewire::actingAs($this->admin)->test(Revenues::class);
        $page->call('create')->assertSet('categoryId', $category->id)
            ->set('amount', '17500')->set('accountId', $this->hdfc->id)->set('customerId', $this->customer->id)->set('reference', 'IMPS-1')->set('description', 'Advance for November')
            ->set('receipt', UploadedFile::fake()->image('receipt.jpg'))->call('save')->assertHasNoErrors();
        $r = BillingRevenue::firstOrFail();
        $this->assertSame([$this->hdfc->id, $category->id, 'Web Development Service'], [$r->account_id, $r->category_id, $r->category]);
        Storage::disk('local')->assertExists($r->receipt_path);
        $this->actingAs($this->admin)->get(route('billing.receipt-file', ['revenue', $r->id]))->assertOk();

        BillingRevenue::create(['date' => '2026-10-01', 'amount' => 99, 'description' => 'Old cash entry']);
        $page->set('account', (string) $this->hdfc->id)->assertSee('Advance for November')->assertDontSee('Old cash entry')
            ->call('resetFilters')->set('from', '2026-10-15')->assertDontSee('Old cash entry')->set('from', '')->set('categoryFilter', (string) $category->id)->assertDontSee('Old cash entry')
            ->call('resetFilters')->assertSee('Old cash entry');

        $page->call('delete', $r->id);
        $this->assertNull($r->fresh());
        Storage::disk('local')->assertMissing($r->receipt_path);
    }

    public function test_invoice_payment_records_the_account_description_and_receipt(): void
    {
        $invoice = BillingInvoice::create(['number' => 1, 'financial_year' => '2026-27', 'customer_id' => $this->customer->id, 'issue_date' => '2026-11-01', 'status' => 'Sent']);
        BillingInvoiceItem::create(['invoice_id' => $invoice->id, 'name' => 'Website', 'quantity' => 1, 'price' => 17500]);

        Livewire::actingAs($this->admin)->test(InvoiceShow::class, ['invoice' => $invoice])
            ->call('openPayment')->assertSet('payAccountId', $this->hdfc->id)->set('payAmount', '5000')->set('payReference', 'NEFT-9')->set('payNote', 'Deduct from advance')
            ->set('payReceipt', UploadedFile::fake()->create('slip.pdf', 20, 'application/pdf'))->call('recordPayment')->assertHasNoErrors()
            ->assertSee('Receipt Summary')->assertSee('HDFC Bank')->assertSee('Deduct from advance')->assertSee('R-');
        $p = BillingInvoicePayment::firstOrFail();
        $this->assertSame([$this->hdfc->id, 'Deduct from advance'], [$p->account_id, $p->note]);
        $this->actingAs($this->admin)->get(route('billing.receipt-file', ['payment', $p->id]))->assertOk();
        $this->assertSame(6000.0, $this->hdfc->balance());

        Livewire::actingAs($this->admin)->test(InvoiceShow::class, ['invoice' => $invoice])->set('payAmount', '1')
            ->set('payReceipt', UploadedFile::fake()->create('virus.exe', 5))->call('recordPayment')->assertHasErrors('payReceipt');
    }

    public function test_credit_notes_list_filters_by_customer_and_date(): void
    {
        $other = BillingCustomer::create(['name' => 'Big Cats India', 'is_active' => true]);
        BillingCreditNote::create(['customer_id' => $this->customer->id, 'amount' => 100, 'date' => '2026-11-04', 'description' => 'sds']);
        BillingCreditNote::create(['customer_id' => $other->id, 'amount' => 50, 'date' => '2026-09-01', 'description' => 'Old discount']);

        Livewire::actingAs($this->admin)->test(CreditNotes::class)->assertSee('sds')->assertSee('Old discount')
            ->set('customer', (string) $this->customer->id)->assertSee('sds')->assertDontSee('Old discount')
            ->call('resetFilters')->set('from', '2026-10-01')->assertDontSee('Old discount')
            ->call('resetFilters')->set('search', 'Old')->assertSee('Old discount')->assertDontSee('>sds<', false);
    }

    public function test_credit_note_form_is_like_the_cms_invoice_amount_date_description(): void
    {
        $invoice = BillingInvoice::create(['number' => 19, 'financial_year' => '2026-27', 'customer_id' => $this->customer->id, 'issue_date' => '2026-10-31', 'status' => 'Sent']);
        BillingInvoiceItem::create(['invoice_id' => $invoice->id, 'name' => 'Work', 'quantity' => 1, 'price' => 500]);

        $page = Livewire::actingAs($this->admin)->test(CreditNotes::class)->call('create')->assertSet('amount', '')
            ->assertSee(['Create New Credit Note', 'Select Invoice', 'Enter Amount', 'Enter Description'])->assertDontSee('— none —');
        $page->set('amount', '100')->call('save')->assertHasErrors('invoiceId');
        $page->set('invoiceId', $invoice->id)->set('amount', '900')->call('save')->assertHasErrors('amount');
        $page->set('amount', '500')->set('description', 'Full waiver')->call('save')->assertHasNoErrors();
        $note = BillingCreditNote::firstOrFail();
        $this->assertSame([$this->customer->id, 'Paid'], [$note->customer_id, $invoice->fresh()->status]);
    }

    public function test_other_users_cannot_open_receipts(): void
    {
        $r = BillingRevenue::create(['date' => '2026-11-01', 'amount' => 1, 'receipt_path' => 'billing/receipts/x.jpg']);
        $employee = User::factory()->create();
        $employee->assignRole('Employee');
        $this->actingAs($employee)->get(route('billing.receipt-file', ['revenue', $r->id]))->assertForbidden();
    }
}
