{{-- Shared design styles for the Sales / billing pages (no build step — included per page). --}}
<style>
    .slip-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px 22px;margin:4px 0 18px;padding-bottom:18px;border-bottom:1px solid var(--line)}
    .slip-meta > div{min-width:0}
    .slip-meta span{display:block;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
    .slip-meta strong{display:block;font-size:14px;margin-top:3px}
    .slip-meta small{display:block;font-size:11px;color:var(--muted);margin-top:1px}
    .bill-badge{display:inline-block;padding:3px 12px;border-radius:999px;font-size:12px;font-weight:600;line-height:1.5;white-space:nowrap}
    .bill-badge.paid,.bill-badge.accepted{background:rgba(16,185,129,.14);color:var(--green,#059669)}
    .bill-badge.part{background:rgba(245,158,11,.16);color:var(--amber,#b45309)}
    .bill-badge.sent{background:rgba(59,130,246,.14);color:#2563eb}
    .bill-badge.converted{background:rgba(139,92,246,.14);color:#7c3aed}
    .bill-badge.draft{background:var(--line-2);color:var(--muted)}
    .bill-badge.unpaid,.bill-badge.declined{background:rgba(239,68,68,.14);color:var(--red,#dc2626)}
    h1 .bill-badge{margin-left:12px;vertical-align:middle}
    .inv-totals{max-width:340px;margin-left:auto;margin-top:18px;border:1px solid var(--line);border-radius:12px;overflow:hidden}
    .inv-totals .r{display:flex;justify-content:space-between;gap:16px;padding:9px 16px;font-size:14px}
    .inv-totals .r span{color:var(--muted)}
    .inv-totals .r.total{border-top:1px solid var(--line);font-weight:700;font-size:15px}
    .inv-totals .r.total span{color:inherit}
    .inv-due{display:flex;justify-content:space-between;align-items:center;padding:13px 16px;background:rgba(239,68,68,.08);border-top:1px solid var(--line)}
    .inv-due.clear{background:rgba(16,185,129,.09)}
    .inv-due .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
    .inv-due b{font-size:19px}
</style>
@php
    if (! function_exists('billing_badge_class')) {
        function billing_badge_class(?string $status): string {
            return ['Paid'=>'paid','Partially Paid'=>'part','Sent'=>'sent','Unpaid'=>'unpaid','Draft'=>'draft',
                'Accepted'=>'accepted','Declined'=>'declined','Converted'=>'converted'][$status] ?? 'draft';
        }
    }
@endphp
