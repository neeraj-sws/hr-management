<?php

namespace Tests\Feature;

use App\Livewire\Billing\InvoiceShow;
use App\Livewire\Billing\Invoices;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingInvoiceItem;
use App\Models\BillingInvoicePayment;
use App\Models\BillingTax;
use App\Models\User;
use App\Services\InvoiceActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Invoice list / detail actions: copy the customer link, duplicate, view, edit, delete. */
class BillingInvoiceActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private BillingInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-05 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $customer = BillingCustomer::create(['name' => 'ELCINA', 'billing_state' => 'Delhi', 'is_active' => true]);
        $gst = BillingTax::create(['name' => 'GST 18%', 'rate' => 18]);
        $this->invoice = BillingInvoice::create(['number' => 1, 'financial_year' => '2026-27', 'customer_id' => $customer->id, 'issue_date' => '2026-05-01', 'due_date' => '2026-05-08',
            'status' => 'Sent', 'send_date' => '2026-05-01', 'notes' => 'Website maintenance', 'place_of_supply' => 'Delhi']);
        BillingInvoiceItem::create(['invoice_id' => $this->invoice->id, 'name' => 'Source India Electronics', 'description' => 'Website maintenance and enhancements', 'quantity' => 1, 'price' => 17500, 'discount' => 0, 'tax_id' => $gst->id]);
    }

    public function test_the_list_shows_every_action_and_the_customer_link_opens_without_signing_in(): void
    {
        $this->actingAs($this->admin)->get(route('billing.invoices'))->assertOk()
            ->assertSee(['Due Amount', 'Copy Invoice', 'Duplicate', '#SWS/2026-27/00001', 'cms-pill sent'], false)->assertSee(route('billing.invoices.show', $this->invoice), false)
            ->assertSee(route('billing.invoices.edit', $this->invoice), false)->assertSee('Delete', false);
        $this->get(route('billing.invoices.show', $this->invoice))->assertOk()->assertSee('Create Invoice')->assertSee('Send Invoice')->assertSee('Get Paid')->assertSee('Credit Note Summary')->assertSee('Copy link');

        $url = app(InvoiceActions::class)->publicUrl($this->invoice);
        auth()->logout();
        $this->get($url)->assertOk()->assertSee('ELCINA')->assertSee('Source India Electronics');
        $this->get(route('billing.invoices.public', $this->invoice))->assertForbidden(); // unsigned
        $this->get($url.'x')->assertForbidden(); // tampered

        $this->invoice->update(['status' => 'Draft']);
        $this->get($url)->assertNotFound();
    }

    public function test_duplicate_makes_a_new_draft_with_the_same_items(): void
    {
        Livewire::actingAs($this->admin)->test(Invoices::class)->call('duplicate', $this->invoice->id)->assertRedirect();
        $copy = BillingInvoice::with('items')->latest('id')->firstOrFail();
        $this->assertNotSame($this->invoice->id, $copy->id);
        $this->assertSame([2, '2026-27', 'Draft', '2026-11-05', '2026-11-12', 'Website maintenance', 1, 17500.0, 'Source India Electronics'],
            [$copy->number, $copy->financial_year, $copy->status, $copy->issue_date->toDateString(), $copy->due_date->toDateString(), $copy->notes, $copy->items->count(), (float) $copy->items->first()->price, $copy->items->first()->name]);
        $this->assertSame(1, $this->invoice->items()->count()); // the original keeps its items

        Livewire::actingAs($this->admin)->test(InvoiceShow::class, ['invoice' => $this->invoice])->call('duplicate')->assertRedirect();
        $this->assertSame(3, BillingInvoice::max('number'));
    }

    public function test_delete_is_refused_while_payments_exist(): void
    {
        BillingInvoicePayment::create(['invoice_id' => $this->invoice->id, 'date' => '2026-05-05', 'amount' => 1000, 'method' => 'Bank']);
        Livewire::actingAs($this->admin)->test(Invoices::class)->call('delete', $this->invoice->id)->assertDispatched('toast');
        $this->assertNotNull($this->invoice->fresh());

        BillingInvoicePayment::query()->delete();
        Livewire::actingAs($this->admin)->test(InvoiceShow::class, ['invoice' => $this->invoice])->call('delete')->assertRedirect(route('billing.invoices'));
        $this->assertNull($this->invoice->fresh());
        $this->assertSame(0, BillingInvoiceItem::count());
    }

    public function test_filters_and_excel_export_of_the_invoice_list(): void
    {
        $other = BillingCustomer::create(['name' => 'Big Cats India', 'billing_state' => 'Madhya Pradesh', 'is_active' => true]);
        BillingInvoice::create(['number' => 2, 'financial_year' => '2026-27', 'customer_id' => $other->id, 'issue_date' => '2026-10-10', 'status' => 'Draft']);

        Livewire::actingAs($this->admin)->test(Invoices::class)->set('fy', '')
            ->assertSee('SWS/2026-27/00002')->set('customer', (string) $this->invoice->customer_id)->assertSee('SWS/2026-27/00001')->assertDontSee('SWS/2026-27/00002')
            ->set('customer', '')->set('from', '2026-10-01')->assertSee('SWS/2026-27/00002')->assertDontSee('SWS/2026-27/00001')
            ->call('resetFilters')->set('search', '00002')->assertSee('SWS/2026-27/00002')->assertDontSee('SWS/2026-27/00001');

        $file = $this->actingAs($this->admin)->get(route('billing.invoices.export', ['customer' => $this->invoice->customer_id]))->assertOk()->getFile()->getPathname();
        $flat = json_encode(\App\Support\XlsxReader::rows($file), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('SWS/2026-27/00001', $flat);
        $this->assertStringContainsString('20650', $flat); // 17500 + 18% GST
        $this->assertStringNotContainsString('Big Cats India', $flat);
    }

    public function test_invoice_is_emailed_and_a_payment_reminder_sent(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $this->invoice->update(['status' => 'Draft', 'send_date' => null]);
        $this->invoice->customer->update(['email' => 'accounts@elcina.test']);

        $page = Livewire::actingAs($this->admin)->test(InvoiceShow::class, ['invoice' => $this->invoice]);
        $page->call('openEmail', 'send')->assertSet('emailTo', 'accounts@elcina.test')->set('emailMessage', 'October bill')->call('sendEmail')->assertHasNoErrors()->assertSet('emailKind', null);
        $this->assertSame('Sent', $this->invoice->fresh()->status);
        \Illuminate\Support\Facades\Notification::assertSentTimes(\App\Notifications\InvoiceEmail::class, 1);
        $page->call('openEmail', 'reminder')->set('emailTo', 'not-an-email')->call('sendEmail')->assertHasErrors('emailTo');
        $page->set('emailTo', 'accounts@elcina.test')->call('sendEmail')->assertHasNoErrors();
        \Illuminate\Support\Facades\Notification::assertSentTimes(\App\Notifications\InvoiceEmail::class, 2);
        $this->assertSame(2, \App\Models\AuditLog::where('action', 'billing.invoice_emailed')->count());
        $page->assertSee('Reminder e-mailed');
    }

    public function test_credit_notes_and_payment_receipts_on_the_invoice(): void
    {
        $page = Livewire::actingAs($this->admin)->test(InvoiceShow::class, ['invoice' => $this->invoice]);
        $page->call('openCredit')->assertSet('creditAmount', '20650')->set('creditAmount', '30000')->call('saveCredit')->assertHasErrors('creditAmount');
        $page->set('creditAmount', '650')->set('creditDescription', 'Rounding off')->call('saveCredit')->assertHasNoErrors()->assertSee('Rounding off');
        $note = \App\Models\BillingCreditNote::firstOrFail();
        $this->assertSame([650.0, 20000.0], [(float) $note->amount, $this->invoice->fresh()->load('items.tax', 'payments', 'creditNotes')->due()]);
        $page->call('openCredit', $note->id)->set('creditAmount', '1000')->call('saveCredit')->assertHasNoErrors();
        $this->assertSame(1000.0, (float) $note->fresh()->amount);

        $payment = BillingInvoicePayment::create(['invoice_id' => $this->invoice->id, 'date' => '2026-11-04', 'amount' => 19650, 'method' => 'UPI', 'reference' => 'UTR123']);
        $this->invoice->load('items.tax', 'payments', 'creditNotes')->refreshStatus();
        $this->assertSame('Paid', $this->invoice->fresh()->status);
        $this->actingAs($this->admin)->get(route('billing.payments.receipt', $payment))->assertOk()->assertSee('Payment Receipt')->assertSee('UTR123')->assertSee('19,650.00');

        $page->call('deleteCredit', $note->id);
        $this->assertNull($note->fresh());
        $this->assertSame('Partially Paid', $this->invoice->fresh()->status);
    }

    public function test_customers_are_exported_and_imported_with_full_addresses(): void
    {
        Livewire::actingAs($this->admin)->test(\App\Livewire\Billing\Customers::class)
            ->call('create')->set('name', 'Payanyfee')->set('billingPhone', '9999')->set('billingAddress', 'MG Road')->set('billingCity', 'Indore')
            ->set('billingState', 'Madhya Pradesh')->set('billingZip', '452001')->call('copyBilling')->assertSet('shippingCity', 'Indore')->call('save')->assertHasNoErrors();
        $c = BillingCustomer::where('name', 'Payanyfee')->firstOrFail();
        $this->assertSame(['Payanyfee', '452001', 'Indore', 'Payanyfee'], [$c->billing_name, $c->billing_zip, $c->shipping_city, $c->shipping_name]);

        // The form is like the CMS: Basic Info + Billing Address, no shipping block; a sample file for importing.
        Livewire::actingAs($this->admin)->test(\App\Livewire\Billing\Customers::class)->call('edit', $c->id)
            ->assertSee(['Edit Customer', 'Basic Info', 'Billing Address', 'Zip Code', 'Update'])->assertDontSee('Shipping address');
        $this->actingAs($this->admin)->get(route('billing.customers.sample'))->assertOk();
        $export = $this->actingAs($this->admin)->get(route('billing.customers.export'))->assertOk()->getFile()->getPathname();
        $this->assertStringContainsString('Payanyfee', json_encode(\App\Support\XlsxReader::rows($export)));

        $path = \App\Support\XlsxWorkbook::toTempFile([['name' => 'Customers', 'columns' => array_map(fn ($h) => [$h, 'text', 12], ['Name', 'Email', 'GSTIN', 'City', 'State']),
            'rows' => [['Satpura Academy', 'office@satpura.test', '23ABCDE1234F1Z5', 'Bhopal', 'Madhya Pradesh'], ['payanyfee', '', '', '', ''], ['', 'x@y.test', '', '', '']]]]);
        Livewire::actingAs($this->admin)->test(\App\Livewire\Billing\Customers::class)
            ->set('importFile', \Illuminate\Http\UploadedFile::fake()->createWithContent('customers.xlsx', file_get_contents($path)))->call('import')->assertHasNoErrors();
        $new = BillingCustomer::where('name', 'Satpura Academy')->firstOrFail();
        $this->assertSame(['office@satpura.test', '23ABCDE1234F1Z5', 'Bhopal'], [$new->email, $new->tax_number, $new->billing_city]);
        $this->assertSame(1, BillingCustomer::whereRaw('LOWER(name) = ?', ['payanyfee'])->count()); // existing name skipped
    }

    public function test_invoice_hours_are_saved_and_shown(): void
    {
        Livewire::actingAs($this->admin)->test(\App\Livewire\Billing\InvoiceForm::class, ['invoice' => $this->invoice])
            ->set('hours', '50')->call('save')->assertHasNoErrors();
        $this->assertSame(50.0, (float) $this->invoice->fresh()->hours);
        $this->actingAs($this->admin)->get(route('billing.invoices.show', $this->invoice))->assertOk()->assertSee('Hours');
        $this->get(route('billing.invoices.print', $this->invoice))->assertOk()->assertSeeInOrder(['Hours :', '50']);
    }

    public function test_employees_cannot_use_the_actions(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole('Employee');
        Livewire::actingAs($employee)->test(Invoices::class)->assertForbidden();
        $this->assertNotNull($this->invoice->fresh());
    }
}
