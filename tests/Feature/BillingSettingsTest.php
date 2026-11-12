<?php

namespace Tests\Feature;

use App\Livewire\Billing\Settings;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Billing settings like the old CMS: logo, colours, prefixes, date format, sender e-mail, QR. */
class BillingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-05 12:00:00');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    public function test_logo_colour_prefixes_and_date_format_show_on_invoices(): void
    {
        $customer = BillingCustomer::create(['name' => 'ELCINA', 'billing_state' => 'Delhi', 'is_active' => true]);
        $invoice = BillingInvoice::create(['number' => 1, 'financial_year' => '2026-27', 'customer_id' => $customer->id, 'issue_date' => '2026-05-01', 'due_date' => '2026-05-08', 'status' => 'Sent']);

        Livewire::actingAs($this->admin)->test(Settings::class)
            ->set('company.name', 'Soham Web Solution')->set('company.invoice_color', '#003580')->set('company.invoice_prefix', '#SWS')
            ->set('company.customer_prefix', '#CL')->set('company.date_format', 'M j, Y')->set('company.mail_from_address', 'accounts@sohamsolution.test')
            ->set('logoFile', UploadedFile::fake()->image('logo.png', 120, 40))->call('save')->assertHasNoErrors()->assertSet('hasLogo', true);
        BillingService::forgetSettings();

        $this->assertSame('#SWS/2026-27/00001', $invoice->fresh()->number());
        $this->assertSame('#CL00001', BillingService::code('customer', 1));
        $this->assertSame('May 1, 2026', BillingService::date('2026-05-01'));
        $print = $this->actingAs($this->admin)->get(route('billing.invoices.print', $invoice))->assertOk();
        $print->assertSee('--acc:#003580', false)->assertSee('data:image/png;base64,', false)->assertSee('May 1, 2026')->assertSee('INVOICE');
        $public = app(\App\Services\InvoiceActions::class)->publicUrl($invoice);
        $this->get($public)->assertOk()->assertSee('invoice-qr', false)->assertSee('Receipt Summary')->assertSee('Product Summary');

        // The logo is kept when other settings change, and can be removed.
        Livewire::actingAs($this->admin)->test(Settings::class)->assertSet('hasLogo', true)->set('company.show_qr', 'off')->call('save')->assertHasNoErrors();
        BillingService::forgetSettings();
        $this->assertStringStartsWith('data:image/png', BillingService::settings()['logo']);
        $this->get($public)->assertDontSee('invoice-qr', false);
        Livewire::actingAs($this->admin)->test(Settings::class)->call('removeLogo')->call('save');
        BillingService::forgetSettings();
        $this->assertSame('', BillingService::settings()['logo']);
    }

    public function test_bad_values_are_refused(): void
    {
        Livewire::actingAs($this->admin)->test(Settings::class)
            ->set('company.invoice_color', 'red')->set('company.invoice_prefix', 'SWS / X')->set('company.date_format', 'Y')
            ->set('logoFile', UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'))->call('save')
            ->assertHasErrors(['company.invoice_color', 'company.invoice_prefix', 'company.date_format', 'logoFile']);
    }
}
