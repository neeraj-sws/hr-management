<?php

namespace App\Console\Commands;

use App\Models\BillingCreditNote;
use App\Models\BillingCustomer;
use App\Models\BillingEstimate;
use App\Models\BillingEstimateItem;
use App\Models\BillingInvoice;
use App\Models\BillingInvoiceItem;
use App\Models\BillingInvoicePayment;
use App\Models\BillingProduct;
use App\Models\BillingRevenue;
use App\Models\BillingTax;
use App\Services\BillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time import of the old SWS CMS billing data (customers, products, taxes,
 * invoices + items, payments, credit notes, revenue, estimates) from its
 * mysqldump .sql file into the HRMS billing_* tables. Re-runnable: it matches
 * rows by their old CMS id (legacy_id) and updates instead of duplicating.
 *
 *   php artisan billing:import-cms "C:\path\to\u841922431_sws_cms.sql"
 */
class ImportCmsBilling extends Command
{
    protected $signature = 'billing:import-cms {file : Path to the CMS mysqldump .sql}';
    protected $description = 'Import customers, products, invoices, payments, credit notes and revenue from the old CMS dump';

    public function handle(BillingService $billing): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) { $this->error("File not found: {$path}"); return self::FAILURE; }
        $sql = file_get_contents($path);
        $this->info('Parsing dump ('.round(strlen($sql) / 1048576, 1).' MB)…');

        DB::transaction(function () use ($sql, $billing) {
            // ---- Taxes ----
            foreach ($this->rows($sql, 'taxes') as $r) {
                BillingTax::updateOrCreate(['legacy_id' => $r['id']],
                    ['name' => $r['name'] ?? 'Tax', 'rate' => $this->num($r['rate'] ?? 0)]);
            }
            $tax = BillingTax::pluck('id', 'legacy_id')->all();

            // ---- Customers ----
            foreach ($this->rows($sql, 'customers') as $r) {
                BillingCustomer::updateOrCreate(['legacy_id' => $r['id']], [
                    'name' => $r['name'] ?: ($r['billing_name'] ?: 'Customer'), 'email' => $r['email'] ?: null,
                    'contact' => $r['contact'] ?? null, 'tax_number' => $r['tax_number'] ?? null,
                    'is_active' => (int) ($r['is_active'] ?? 1) === 1,
                    'billing_name' => $r['billing_name'] ?? null, 'billing_phone' => $r['billing_phone'] ?? null,
                    'billing_address' => $r['billing_address'] ?? null, 'billing_city' => $r['billing_city'] ?? null,
                    'billing_state' => $r['billing_state'] ?? null, 'billing_country' => $r['billing_country'] ?? null,
                    'billing_zip' => $r['billing_zip'] ?? null,
                    'shipping_name' => $r['shipping_name'] ?? null, 'shipping_phone' => $r['shipping_phone'] ?? null,
                    'shipping_address' => $r['shipping_address'] ?? null, 'shipping_city' => $r['shipping_city'] ?? null,
                    'shipping_state' => $r['shipping_state'] ?? null, 'shipping_country' => $r['shipping_country'] ?? null,
                    'shipping_zip' => $r['shipping_zip'] ?? null,
                ]);
            }
            $cust = BillingCustomer::pluck('id', 'legacy_id')->all();

            // ---- Products / services ----
            foreach ($this->rows($sql, 'product_services') as $r) {
                BillingProduct::updateOrCreate(['legacy_id' => $r['id']], [
                    'name' => $r['name'] ?: 'Item', 'sku' => $r['sku'] ?? null, 'type' => $r['type'] ?: 'service',
                    'sale_price' => $this->num($r['sale_price'] ?? 0), 'purchase_price' => $this->num($r['purchase_price'] ?? 0),
                    'tax_id' => $tax[(int) ($r['tax_id'] ?? 0)] ?? null, 'description' => $r['description'] ?? null,
                ]);
            }
            $prod = BillingProduct::pluck('id', 'legacy_id')->all();

            // ---- Invoices ----  (status int -> label; FY + per-year number from issue_date)
            $statusMap = [0 => 'Draft', 1 => 'Sent', 2 => 'Unpaid', 3 => 'Partially Paid', 4 => 'Paid'];
            $invoices = $this->rows($sql, 'invoices');
            usort($invoices, fn ($a, $b) => [$a['issue_date'], (int) $a['id']] <=> [$b['issue_date'], (int) $b['id']]);
            $counter = [];
            $invMap = [];
            foreach ($invoices as $r) {
                $fy = $billing->financialYear($r['issue_date']);
                $counter[$fy] = ($counter[$fy] ?? 0) + 1;
                $inv = BillingInvoice::updateOrCreate(['legacy_id' => $r['id']], [
                    'number' => $counter[$fy], 'financial_year' => $fy,
                    'customer_id' => $cust[(int) $r['customer_id']] ?? null,
                    'issue_date' => $r['issue_date'], 'due_date' => $r['due_date'] ?: $r['issue_date'],
                    'send_date' => $r['send_date'] ?? null, 'ref_number' => $r['ref_number'] ?? null,
                    'status' => $statusMap[(int) ($r['status'] ?? 0)] ?? 'Sent',
                ]);
                if (! $inv->customer_id) { $inv->delete(); continue; } // orphan invoice (customer gone)
                $invMap[(int) $r['id']] = $inv->id;
            }

            // ---- Invoice items ----
            BillingInvoiceItem::whereIn('invoice_id', array_values($invMap))->delete();
            foreach ($this->rows($sql, 'invoice_products') as $r) {
                $iid = $invMap[(int) $r['invoice_id']] ?? null;
                if (! $iid) continue;
                $pid = $prod[(int) ($r['product_id'] ?? 0)] ?? null;
                BillingInvoiceItem::create([
                    'invoice_id' => $iid, 'product_id' => $pid,
                    'name' => BillingProduct::find($pid)?->name ?? ($r['description'] ?: 'Item'),
                    'description' => $r['description'] ?? null, 'quantity' => $this->num($r['quantity'] ?? 1),
                    'price' => $this->num($r['price'] ?? 0), 'discount' => $this->num($r['discount'] ?? 0),
                    'tax_id' => $tax[(int) ($r['tax'] ?? 0)] ?? null,
                ]);
            }

            // ---- Payments ----
            BillingInvoicePayment::whereIn('invoice_id', array_values($invMap))->delete();
            foreach ($this->rows($sql, 'invoice_payments') as $r) {
                $iid = $invMap[(int) $r['invoice_id']] ?? null;
                if (! $iid) continue;
                BillingInvoicePayment::create(['invoice_id' => $iid, 'date' => $r['date'] ?? ($r['created_at'] ?? now()->toDateString()),
                    'amount' => $this->num($r['amount'] ?? 0), 'method' => $r['type'] ?? ($r['payment_type'] ?? null), 'reference' => $r['reference'] ?? null]);
            }

            // ---- Credit notes ----
            foreach ($this->rows($sql, 'credit_notes') as $r) {
                BillingCreditNote::updateOrCreate(['legacy_id' => $r['id']], [
                    'invoice_id' => $invMap[(int) ($r['invoice'] ?? 0)] ?? null, 'customer_id' => $cust[(int) ($r['customer'] ?? 0)] ?? null,
                    'amount' => $this->num($r['amount'] ?? 0), 'date' => $r['date'] ?? now()->toDateString(), 'description' => $r['description'] ?? null,
                ]);
            }

            // ---- Revenue ----
            foreach ($this->rows($sql, 'revenues') as $r) {
                BillingRevenue::updateOrCreate(['legacy_id' => $r['id']], [
                    'date' => $r['date'] ?? now()->toDateString(), 'amount' => $this->num($r['amount'] ?? 0),
                    'customer_id' => $cust[(int) ($r['customer_id'] ?? 0)] ?? null, 'reference' => $r['reference'] ?? null, 'description' => $r['description'] ?? null,
                ]);
            }

            // ---- Estimates (proposals) ----
            $proposals = $this->rows($sql, 'proposals');
            usort($proposals, fn ($a, $b) => [$a['issue_date'], (int) $a['id']] <=> [$b['issue_date'], (int) $b['id']]);
            $ecount = [];
            $estMap = [];
            foreach ($proposals as $r) {
                $fy = $billing->financialYear($r['issue_date']);
                $ecount[$fy] = ($ecount[$fy] ?? 0) + 1;
                $cid = $cust[(int) $r['customer_id']] ?? null;
                if (! $cid) continue;
                $est = BillingEstimate::updateOrCreate(['legacy_id' => $r['id']], [
                    'number' => $ecount[$fy], 'financial_year' => $fy, 'customer_id' => $cid, 'issue_date' => $r['issue_date'],
                    'status' => (int) ($r['is_convert'] ?? 0) === 1 ? 'Converted' : 'Sent',
                ]);
                $estMap[(int) $r['id']] = $est->id;
            }
            BillingEstimateItem::whereIn('estimate_id', array_values($estMap))->delete();
            foreach ($this->rows($sql, 'proposal_products') as $r) {
                $eid = $estMap[(int) $r['proposal_id']] ?? null;
                if (! $eid) continue;
                $pid = $prod[(int) ($r['product_id'] ?? 0)] ?? null;
                BillingEstimateItem::create(['estimate_id' => $eid, 'product_id' => $pid,
                    'name' => BillingProduct::find($pid)?->name ?? ($r['description'] ?: 'Item'), 'description' => $r['description'] ?? null,
                    'quantity' => $this->num($r['quantity'] ?? 1), 'price' => $this->num($r['price'] ?? 0), 'discount' => $this->num($r['discount'] ?? 0),
                    'tax_id' => $tax[(int) ($r['tax'] ?? 0)] ?? null]);
            }
        });

        $this->info('Done.');
        $this->table(['Table', 'Rows'], [
            ['Customers', BillingCustomer::count()], ['Products', BillingProduct::count()], ['Taxes', BillingTax::count()],
            ['Invoices', BillingInvoice::count()], ['Invoice items', BillingInvoiceItem::count()], ['Payments', BillingInvoicePayment::count()],
            ['Credit notes', BillingCreditNote::count()], ['Revenue', BillingRevenue::count()], ['Estimates', BillingEstimate::count()],
        ]);

        return self::SUCCESS;
    }

    /** All rows of a mysqldump table as [column => value] maps. */
    private function rows(string $sql, string $table): array
    {
        // Column list from the CREATE TABLE (keeps order; backtick names).
        if (! preg_match('/CREATE TABLE `'.preg_quote($table, '/').'` \((.*?)\n\) ENGINE/s', $sql, $cm)) return [];
        preg_match_all('/^\s*`([a-zA-Z0-9_]+)`/m', $cm[1], $colm);
        $cols = $colm[1];
        if (! $cols) return [];

        $rows = [];
        // Every INSERT INTO `table` ... VALUES (...),(...);  (there may be several)
        if (preg_match_all('/INSERT INTO `'.preg_quote($table, '/').'`[^;]*?VALUES\s*(.*?);\s*\n/s', $sql, $im)) {
            foreach ($im[1] as $block) {
                foreach ($this->tuples($block) as $vals) {
                    if (count($vals) !== count($cols)) continue;
                    $rows[] = array_combine($cols, $vals);
                }
            }
        }

        return $rows;
    }

    /** Split "(..),(..),(..)" into arrays of scalar values, respecting quoted strings. */
    private function tuples(string $block): array
    {
        $out = [];
        $len = strlen($block);
        $i = 0;
        while ($i < $len) {
            if ($block[$i] !== '(') { $i++; continue; }
            $i++; // past '('
            $vals = [];
            $cur = '';
            $inStr = false;
            while ($i < $len) {
                $ch = $block[$i];
                if ($inStr) {
                    if ($ch === '\\' && $i + 1 < $len) { $cur .= $this->unescape($block[$i + 1]); $i += 2; continue; }
                    if ($ch === "'" && ($i + 1 < $len && $block[$i + 1] === "'")) { $cur .= "'"; $i += 2; continue; }
                    if ($ch === "'") { $inStr = false; $i++; continue; }
                    $cur .= $ch; $i++; continue;
                }
                if ($ch === "'") { $inStr = true; $cur = ($cur === '' ? '' : $cur); $i++; $cur = "\0STR\0".$cur; continue; }
                if ($ch === ',') { $vals[] = $this->scalar($cur); $cur = ''; $i++; continue; }
                if ($ch === ')') { $vals[] = $this->scalar($cur); $i++; break; }
                $cur .= $ch; $i++;
            }
            $out[] = $vals;
            // skip to next '(' or end
            while ($i < $len && $block[$i] !== '(') $i++;
        }

        return $out;
    }

    private function scalar(string $raw): ?string
    {
        if (str_starts_with($raw, "\0STR\0")) return substr($raw, 5);
        $t = trim($raw);
        if ($t === 'NULL' || $t === '') return null;

        return $t;
    }

    private function unescape(string $c): string
    {
        return match ($c) { 'n' => "\n", 't' => "\t", 'r' => "\r", '0' => "\0", default => $c };
    }

    private function num($v): float
    {
        return (float) preg_replace('/[^0-9.\-]/', '', (string) $v);
    }
}
