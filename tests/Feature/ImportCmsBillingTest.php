<?php

namespace Tests\Feature;

use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingInvoicePayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The CMS import keeps the CMS invoice numbers, brings the archived period (2025-26) and is safe to run again. */
class ImportCmsBillingTest extends TestCase
{
    use RefreshDatabase;

    private function dump(): string
    {
        $sql = <<<'SQL'
CREATE TABLE `settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `value` text NOT NULL
) ENGINE=InnoDB;
INSERT INTO `settings` (`id`, `name`, `value`) VALUES
(1, 'invoice_prefix', '#SWS/2026-27/');

CREATE TABLE `customers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) NOT NULL,
  `name` varchar(191) NOT NULL,
  `email` varchar(191) DEFAULT NULL,
  `billing_name` varchar(191) DEFAULT NULL,
  `billing_state` varchar(191) DEFAULT NULL,
  `balance` decimal(15,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB;
INSERT INTO `customers` (`id`, `customer_id`, `name`, `email`, `billing_name`, `billing_state`, `balance`) VALUES
(19, 15, 'ELCINA', 'accounts@elcina.com', 'ELECTRONIC INDUSTRIES ASSOCIATION OF INDIA (ELCINA)', 'DL', -999999.99),
(17, 13, 'Avdhesh Sharma', 'info@detecvision.com', 'Detecvision Technologies', 'DL', -60000.00),
(2, 2, 'Priyank Tiwari', 'priyank@webking-services.com', 'Web King Services', 'Delhi', -118306.00);

CREATE TABLE `invoices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `issue_date` date NOT NULL,
  `due_date` date NOT NULL,
  `send_date` date DEFAULT NULL,
  `category_id` int(11) NOT NULL,
  `ref_number` text DEFAULT NULL,
  `status` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB;
INSERT INTO `invoices` (`id`, `invoice_id`, `customer_id`, `issue_date`, `due_date`, `send_date`, `category_id`, `ref_number`, `status`) VALUES
(1, 1, 19, '2026-05-01', '2026-05-08', '2026-05-01', 4, NULL, 1),
(2, 2, 17, '2026-04-29', '2026-05-04', '2026-05-01', 4, NULL, 1);

CREATE TABLE `invoice_products` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` double(25,2) NOT NULL DEFAULT 0.00,
  `tax` varchar(50) DEFAULT NULL,
  `discount` double(8,2) NOT NULL DEFAULT 0.00,
  `price` decimal(16,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL
) ENGINE=InnoDB;
INSERT INTO `invoice_products` (`id`, `invoice_id`, `product_id`, `quantity`, `tax`, `discount`, `price`, `description`) VALUES
(1, 1, 11, 1.00, NULL, 0.00, 17500.00, 'Website maintenance and enhancements'),
(2, 2, 38, 18.00, NULL, 0.00, 650.00, 'Neeraj Work - JGU');

CREATE TABLE `invoices_old_01-05-2026` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `issue_date` date NOT NULL,
  `due_date` date NOT NULL,
  `send_date` date DEFAULT NULL,
  `category_id` int(11) NOT NULL,
  `ref_number` text DEFAULT NULL,
  `status` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB;
INSERT INTO `invoices_old_01-05-2026` (`id`, `invoice_id`, `customer_id`, `issue_date`, `due_date`, `send_date`, `category_id`, `ref_number`, `status`) VALUES
(65, 65, 17, '2026-03-02', '2026-03-07', '2026-03-02', 4, NULL, 1),
(74, 71, 19, '2026-03-01', '2026-03-10', '2026-03-01', 4, NULL, 4);

CREATE TABLE `invoice_products_old_01-05-2026` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` double(25,2) NOT NULL DEFAULT 0.00,
  `tax` varchar(50) DEFAULT NULL,
  `discount` double(8,2) NOT NULL DEFAULT 0.00,
  `price` decimal(16,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL
) ENGINE=InnoDB;
INSERT INTO `invoice_products_old_01-05-2026` (`id`, `invoice_id`, `product_id`, `quantity`, `tax`, `discount`, `price`, `description`) VALUES
(79, 65, 38, 65.00, NULL, 0.00, 650.00, 'Neeraj Work- JGU and ISI'),
(89, 74, 38, 1.00, NULL, 0.00, 7500.00, 'Source India Electronics V2 - Final payment');

CREATE TABLE `invoice_payments_old_01-05-2026` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) NOT NULL,
  `date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `account_id` int(11) NOT NULL,
  `payment_type` varchar(191) DEFAULT NULL,
  `reference` varchar(191) DEFAULT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB;
INSERT INTO `invoice_payments_old_01-05-2026` (`id`, `invoice_id`, `date`, `amount`, `account_id`, `payment_type`, `reference`, `description`) VALUES
(30, 74, '2026-03-20', 7500.00, 3, 'Manually', 'NEFT-1', 'Final payment');
SQL;
        $path = tempnam(sys_get_temp_dir(), 'cms').'.sql';
        file_put_contents($path, $sql."\n"); // a real mysqldump ends every statement with a newline

        return $path;
    }

    public function test_cms_numbers_old_period_and_safe_rerun(): void
    {
        $this->seed();
        $path = $this->dump();
        $this->artisan('billing:import-cms', ['file' => $path])->assertSuccessful();

        $find = fn (string $fy, int $n) => BillingInvoice::with('customer', 'items.tax', 'payments', 'creditNotes')->where('financial_year', $fy)->where('number', $n)->firstOrFail();
        $a = $find('2026-27', 1);
        $this->assertSame(['SWS/2026-27/00001', 'ELCINA', 17500.0], [$a->number(), $a->customer->name, $a->total()]);
        $b = $find('2026-27', 2); // issued before invoice 1 but keeps the CMS number 2
        $this->assertSame(['Avdhesh Sharma', 11700.0], [$b->customer->name, $b->total()]);
        $old = $find('2025-26', 65);
        $this->assertSame(['SWS/2025-26/00065', 'Avdhesh Sharma', 42250.0], [$old->number(), $old->customer->name, $old->total()]);
        $paid = $find('2025-26', 71);
        $this->assertSame(['ELCINA', 7500.0, 0.0, 'Paid'], [$paid->customer->name, $paid->paidAmount(), $paid->due(), $paid->status]);

        // A payment added in HRMS after the import survives a second import; nothing is doubled.
        BillingInvoicePayment::create(['invoice_id' => $a->id, 'date' => '2026-11-01', 'amount' => 1000, 'method' => 'UPI', 'recorded_by' => $this->admin->id ?? 1]);
        $this->artisan('billing:import-cms', ['file' => $path])->assertSuccessful();
        $this->assertSame([4, 4, 2, 1000.0], [BillingInvoice::count(), \App\Models\BillingInvoiceItem::count(), BillingInvoicePayment::count(), (float) $a->payments()->sum('amount')]);
        $this->assertSame(3, BillingCustomer::count());
    }

    public function test_customer_numbers_and_cms_balance_carry_over(): void
    {
        $this->seed();
        $this->artisan('billing:import-cms', ['file' => $this->dump()])->assertSuccessful();
        $priyank = BillingCustomer::where('name', 'Priyank Tiwari')->firstOrFail();
        $avdhesh = BillingCustomer::where('name', 'Avdhesh Sharma')->firstOrFail();
        $elcina = BillingCustomer::where('name', 'ELCINA')->firstOrFail();
        $this->assertSame(['#CUST00002', -118306.0], [$priyank->code(), $priyank->cmsBalance()]); // no invoices here: all from 2024-25
        // CMS owed 60,000 = invoices here (11,700 + 42,250) + 6,050 from before
        $this->assertSame(['#CUST00013', 6050.0, -60000.0], [$avdhesh->code(), (float) $avdhesh->opening_balance, $avdhesh->cmsBalance()]);
        $this->assertSame([0.0, -17500.0], [(float) $elcina->opening_balance, $elcina->cmsBalance()]); // CMS figure at its limit: from the invoices
        $this->assertSame('#CUST00016', BillingCustomer::create(['name' => 'New one', 'is_active' => true])->code()); // continues the numbering
    }

    public function test_invoices_made_by_an_earlier_import_are_matched_not_doubled(): void
    {
        $this->seed();
        // An earlier import put the archived invoice 65 into 2025-26 as number 1, with the old id as legacy id (which clashes
        // with the current CMS invoice 1's id), and numbered the current invoices by date.
        $avdhesh = BillingCustomer::create(['name' => 'Avdhesh Sharma', 'legacy_id' => 17, 'is_active' => true]);
        $elcina = BillingCustomer::create(['name' => 'ELCINA', 'legacy_id' => 19, 'is_active' => true]);
        $old = BillingInvoice::create(['number' => 1, 'financial_year' => '2025-26', 'customer_id' => $avdhesh->id, 'issue_date' => '2026-03-02', 'status' => 'Sent', 'legacy_id' => 65]);
        \App\Models\BillingInvoiceItem::create(['invoice_id' => $old->id, 'name' => 'Old item', 'quantity' => 65, 'price' => 650]);
        $cur = BillingInvoice::create(['number' => 2, 'financial_year' => '2026-27', 'customer_id' => $elcina->id, 'issue_date' => '2026-05-01', 'status' => 'Sent', 'legacy_id' => 1]);
        $native = BillingInvoice::create(['number' => 3, 'financial_year' => '2026-27', 'customer_id' => $elcina->id, 'issue_date' => '2026-10-31', 'status' => 'Draft']);

        $this->artisan('billing:import-cms', ['file' => $this->dump()])->assertSuccessful();

        $this->assertSame([65, '2025-26', 1000065], [$old->fresh()->number, $old->fresh()->financial_year, $old->fresh()->legacy_id]);
        $this->assertSame(1, $old->items()->count()); // kept, not doubled
        $this->assertSame(1, $cur->fresh()->number);
        $this->assertSame(3, $native->fresh()->number); // HRMS-made invoice keeps its place after the CMS numbers
        $this->assertSame(5, BillingInvoice::count()); // 2 current + 2 archived + the HRMS one
        $this->assertSame(0, BillingInvoice::where('number', '>', 900000)->count());
    }
}
