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
                    'customer_number' => (int) ($r['customer_id'] ?? 0) ?: null, // #CUST00001 in the CMS
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
            // ---- Bank / cash accounts ----
            $acc = [];
            $newAcc = [];
            foreach ($this->rows($sql, 'bank_accounts') as $r) {
                $isCash = strcasecmp(trim((string) ($r['bank_name'] ?? '')), 'cash') === 0;
                $a = \App\Models\BillingBankAccount::where('legacy_id', $r['id'])->first()
                    ?? ($isCash ? \App\Models\BillingBankAccount::whereNull('legacy_id')->where('bank_name', 'Cash')->first() : null)
                    ?? new \App\Models\BillingBankAccount();
                $alreadyImported = $a->exists && $a->legacy_id !== null;
                $a->fill(['legacy_id' => $r['id'], 'holder_name' => trim((string) ($r['holder_name'] ?? '')) ?: ($isCash ? 'cash' : 'Account'), 'bank_name' => $isCash ? 'Cash' : ($r['bank_name'] ?: 'Bank'),
                    'account_number' => trim((string) ($r['account_number'] ?? ''), " -") ?: null, 'opening_balance' => $this->num($r['opening_balance'] ?? 0),
                    'contact' => trim((string) ($r['contact_number'] ?? ''), " -") ?: null, 'address' => trim((string) ($r['bank_address'] ?? ''), " -") ?: null, 'is_active' => true])->save();
                $acc[(int) $r['id']] = $a->id;
                if ($alreadyImported) continue;
                $newAcc[(int) $r['id']] = true;
            }

            // A second, unused "Cash" account (the accounts migration run twice) is just a duplicate.
            if ($acc) {
                foreach (\App\Models\BillingBankAccount::whereNull('legacy_id')->where('bank_name', 'Cash')->get() as $spare) {
                    if (! $spare->inUse()) $spare->delete();
                }
            }

            // ---- Invoice footer (bank details) and signatory text from the CMS settings, only where HRMS has none yet ----
            $cmsSet = collect($this->rows($sql, 'settings'))->pluck('value', 'name');
            $have = app(\App\Services\PolicyConfigurationService::class)->forEmployee(null, 'billing.company', today()->toDateString(), []);
            $fill = [];
            if (empty($have['footer_notes']) && ! empty($cmsSet['footer_notes'])) $fill['footer_notes'] = BillingService::safeHtml($cmsSet['footer_notes']);
            if (empty($have['footer_title']) && ! empty($cmsSet['footer_title'])) $fill['footer_title'] = trim($cmsSet['footer_title']);
            if (empty($have['signatory'])) $fill['signatory'] = 'Neeraj Malakar';
            if ($fill) {
                app(\App\Services\PeopleOperationsService::class)->setPolicy('billing.company', 'billing', 'Billing company details', array_merge($have, $fill), null, (int) (\App\Models\User::orderBy('id')->value('id') ?? 1), today()->toDateString());
                BillingService::forgetSettings();
            }

            // ---- Income / expense categories ----
            $cat = [];
            foreach ($this->rows($sql, 'product_service_categories') as $r) {
                $type = ['income' => 'income', 'expense' => 'expense'][strtolower((string) ($r['type'] ?? ''))] ?? 'product';
                $c = \App\Models\BillingCategory::updateOrCreate(['legacy_id' => $r['id']], ['name' => $r['name'], 'type' => $type,
                    'color' => preg_match('/^[0-9a-f]{6}$/i', (string) ($r['color'] ?? '')) ? '#'.strtolower($r['color']) : null]);
                $cat[(int) $r['id']] = $c->id;
            }

            // The CMS shows invoices as <prefix><invoice_id>, the prefix holding the year: "#SWS/2026-27/" + 1 = #SWS/2026-27/00001.
            // Each period was archived in its own tables when the prefix changed (invoices_old_01-05-2026 = 2025-26).
            preg_match("/'invoice_prefix',\s*'[^']*?(\d{4}-\d{2})/", $sql, $pm);
            $currentFy = $pm[1] ?? $billing->financialYear(today());
            $periods = [
                // [invoices table, items table, payments table, financial year, legacy-id offset]
                ['invoices', 'invoice_products', 'invoice_payments', $currentFy, 0],
                ['invoices_old_01-05-2026', 'invoice_products_old_01-05-2026', 'invoice_payments_old_01-05-2026', $this->previousFy($currentFy), self::OLD_PERIOD_OFFSET],
            ];
            $claimed = [];       // HRMS invoice ids already matched to a CMS invoice in this run
            $invMap = [];        // current period: CMS id => HRMS id (also used by credit notes / hours)
            $fresh = [];         // HRMS invoice ids created in this run
            $sync = [];          // HRMS invoice ids whose items are taken from the CMS (new, or made by an earlier import)
            foreach ($periods as [$invTable, $itemTable, $payTable, $fy, $offset]) {
                $map = [];
                $rowsOf = $this->rows($sql, $invTable);
                // Park every number of this year first, so giving the CMS numbers never hits the (year, number) unique key;
                // whatever is not a CMS invoice gets its number back (in the same order) after the CMS numbers.
                BillingInvoice::where('financial_year', $fy)->where('number', '<', 900000)->update(['number' => DB::raw('number + 900000')]);
                foreach ($rowsOf as $r) {
                    $existing = $this->findImported($r, $offset, $cust[(int) $r['customer_id']] ?? null, $claimed);
                    $values = [
                        'number' => (int) ($r['invoice_id'] ?: $r['id']), 'financial_year' => $fy,
                        'customer_id' => $cust[(int) $r['customer_id']] ?? null,
                        'category_id' => $cat[(int) ($r['category_id'] ?? 0)] ?? null,
                        'issue_date' => $r['issue_date'], 'due_date' => $r['due_date'] ?: $r['issue_date'],
                        'send_date' => $r['send_date'] ?? null, 'ref_number' => $r['ref_number'] ?? null,
                    ];
                    if (! $values['customer_id']) { $existing?->delete(); continue; } // orphan invoice (customer gone)
                    if ($existing) {
                        // Fix number, year, customer, dates and mark it as this CMS invoice. Lines come from the CMS too when an
                        // earlier import made it; an invoice typed in HRMS keeps its own lines.
                        if ($existing->legacy_id !== null) $sync[$existing->id] = true;
                        $existing->update($values + ['legacy_id' => $offset + (int) $r['id']]);
                        $inv = $existing;
                    } else {
                        $inv = BillingInvoice::create($values + ['legacy_id' => $offset + (int) $r['id'], 'status' => $statusMap[(int) ($r['status'] ?? 0)] ?? 'Sent']);
                        $fresh[$inv->id] = true;
                        $sync[$inv->id] = true;
                    }
                    $map[(int) $r['id']] = $inv->id;
                    $claimed[$inv->id] = true;
                }
                // Anything still parked was removed from the CMS since: put it back after the CMS numbers.
                foreach (BillingInvoice::where('financial_year', $fy)->where('number', '>', 900000)->orderBy('number')->get() as $left) {
                    $left->update(['number' => (int) BillingInvoice::where('financial_year', $fy)->where('number', '<', 900000)->max('number') + 1]);
                }

                // Items: replaced by the CMS lines. Payments: the CMS payments (recorded_by empty) are replaced every run, so
                // an invoice imported earlier without them gets them now; payments recorded in HRMS stay.
                $syncIds = array_values(array_filter($map, fn ($id) => isset($sync[$id])));
                BillingInvoiceItem::whereIn('invoice_id', $syncIds)->delete();
                BillingInvoicePayment::whereIn('invoice_id', array_values($map))->whereNull('recorded_by')->delete();
                foreach ($this->rows($sql, $itemTable) as $r) {
                    $iid = $map[(int) $r['invoice_id']] ?? null;
                    if (! $iid || ! isset($sync[$iid])) continue;
                    $pid = $prod[(int) ($r['product_id'] ?? 0)] ?? null;
                    BillingInvoiceItem::create([
                        'invoice_id' => $iid, 'product_id' => $pid,
                        'name' => BillingProduct::find($pid)?->name ?? ($r['description'] ?: 'Item'),
                        'description' => $r['description'] ?? null, 'quantity' => $this->num($r['quantity'] ?? 1),
                        'price' => $this->num($r['price'] ?? 0), 'discount' => $this->num($r['discount'] ?? 0),
                        'tax_id' => $tax[(int) trim((string) ($r['tax'] ?? '0'), "' ")] ?? null,
                    ]);
                }
                foreach ($this->rows($sql, $payTable) as $r) {
                    $iid = $map[(int) $r['invoice_id']] ?? null;
                    if (! $iid) continue;
                    BillingInvoicePayment::create(['invoice_id' => $iid, 'date' => $r['date'] ?? ($r['created_at'] ?? now()->toDateString()),
                        'amount' => $this->num($r['amount'] ?? 0), 'method' => ($r['payment_type'] ?? null) ?: 'Manually', 'reference' => $r['reference'] ?? null,
                        'account_id' => $acc[(int) ($r['account_id'] ?? 0)] ?? null, 'note' => ($r['description'] ?? null) ?: null]);
                }
                // Status from what was paid (Draft stays Draft).
                foreach (BillingInvoice::with('items.tax', 'payments', 'creditNotes')->whereIn('id', array_values($map))->get() as $inv) {
                    $inv->refreshStatus();
                }
                if ($offset === 0) $invMap = $map;
                $this->line(sprintf('  %s: %d invoices (FY %s), %d new', $invTable, count($map), $fy, count(array_intersect_key($fresh, array_flip($map)))));
            }

            // ---- "Hours" (an invoice custom field in the CMS) ----
            $hoursField = collect($this->rows($sql, 'custom_fields'))->first(fn ($f) => strcasecmp(trim((string) $f['name']), 'Hours') === 0 && strcasecmp((string) $f['module'], 'Invoice') === 0);
            if ($hoursField) {
                foreach ($this->rows($sql, 'custom_field_values') as $v) {
                    if ((int) $v['field_id'] !== (int) $hoursField['id'] || ! is_numeric($v['value'] ?? null)) continue;
                    if ($iid = $invMap[(int) $v['record_id']] ?? null) BillingInvoice::whereKey($iid)->update(['hours' => (float) $v['value']]);
                }
            }

            // ---- Customer balance carried over from the CMS ----
            // CMS "balance" = payments − invoiced since the start (negative = owed), so it also holds years whose invoices
            // are not in the dump (2024-25). The part not covered by the imported invoices becomes the opening balance —
            // set once (first import), so later HRMS payments are not counted twice. A balance stuck at the CMS column's
            // limit (±999,999.99) is not a real figure: those customers start from 0.
            foreach ($this->rows($sql, 'customers') as $r) {
                $c = BillingCustomer::where('legacy_id', $r['id'])->first();
                if (! $c || $c->opening_balance !== null) continue;
                $cmsBalance = $this->num($r['balance'] ?? 0);
                $importedDue = BillingInvoice::with(['items.tax', 'payments', 'creditNotes'])->where('customer_id', $c->id)->whereNotNull('legacy_id')->where('status', '!=', 'Draft')->get()
                    ->sum(fn ($i) => $i->total() - (float) $i->payments->whereNull('recorded_by')->sum('amount') - $i->creditTotal());
                $opening = abs($cmsBalance) >= 999999 ? 0.0 : round(-$cmsBalance - $importedDue, 2);
                $c->update(['opening_balance' => $opening]);
                if (abs($cmsBalance) >= 999999) $this->warn("  {$c->name}: CMS balance {$cmsBalance} is at the CMS limit — opening set to 0, balance comes from the invoices.");
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
                    'account_id' => $acc[(int) ($r['account_id'] ?? 0)] ?? null, 'category_id' => $cat[(int) ($r['category_id'] ?? 0)] ?? null,
                    'category' => isset($cat[(int) ($r['category_id'] ?? 0)]) ? \App\Models\BillingCategory::find($cat[(int) $r['category_id']])?->name : null,
                ]);
            }

            // The CMS keeps each account's running balance in "opening_balance"; HRMS adds payments and
            // revenue on top of the opening, so the opening is the CMS balance less what was imported into it.
            foreach ($this->rows($sql, 'bank_accounts') as $r) {
                if (! ($id = $acc[(int) $r['id']] ?? null) || ! isset($newAcc[(int) $r['id']])) continue; // only the first import sets the opening
                $received = (float) BillingInvoicePayment::where('account_id', $id)->sum('amount') + (float) BillingRevenue::where('account_id', $id)->sum('amount');
                \App\Models\BillingBankAccount::whereKey($id)->update(['opening_balance' => round($this->num($r['opening_balance'] ?? 0) - $received, 2)]);
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

    /** legacy_id of an invoice from the archived period = this + its id in invoices_old_01-05-2026 (keeps it apart from the current CMS ids). */
    public const OLD_PERIOD_OFFSET = 1000000;

    /**
     * The HRMS invoice that already stands for this CMS invoice, if any: the one this import made (legacy id), or one an
     * earlier import made under another legacy id / number — same customer and issue date. Never one already matched.
     */
    private function findImported(array $r, int $offset, ?int $customerId, array $claimed): ?BillingInvoice
    {
        $exact = BillingInvoice::where('legacy_id', $offset + (int) $r['id'])->whereNotIn('id', array_keys($claimed))->first();
        if ($exact && (! $customerId || ($exact->customer_id === $customerId && $exact->issue_date->toDateString() === $r['issue_date']))) return $exact;
        if (! $customerId) return null;

        $number = (int) ($r['invoice_id'] ?: $r['id']);
        $candidates = BillingInvoice::where('customer_id', $customerId)->whereDate('issue_date', $r['issue_date'])
            ->whereNotIn('id', array_keys($claimed))
            ->where(fn ($q) => $q->whereNull('legacy_id')->orWhere('legacy_id', (int) $r['id'])->orWhere('legacy_id', $offset + (int) $r['id'])->orWhere('legacy_id', '<', self::OLD_PERIOD_OFFSET))
            ->get();

        // Prefer the same old id, then the same number, then the first one.
        return $candidates->sortBy(fn ($i) => [(int) $i->legacy_id === (int) $r['id'] ? 0 : 1, ((int) $i->number % 900000) === $number ? 0 : 1, $i->id])->first();
    }

    /** "2026-27" → "2025-26". */
    private function previousFy(string $fy): string
    {
        $start = (int) substr($fy, 0, 4) - 1;

        return $start.'-'.substr((string) ($start + 1), 2);
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
                // A string starts: drop the space after the previous comma so values don't begin with " ".
                if ($ch === "'") { $inStr = true; $i++; $cur = "\0STR\0"; continue; }
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
