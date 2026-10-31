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
    /* Line-items table: item column reads left, numbers right, tidy rows */
    .bill-items th,.bill-items td{vertical-align:top}
    .bill-items td:nth-child(2),.bill-items th:nth-child(2){text-align:left}
    .bill-items td:nth-child(2) strong{display:block}
    .bill-items td:nth-child(2) small{display:block;color:var(--muted);margin-top:2px;white-space:normal;text-align:left}
    .bill-items td:first-child,.bill-items th:first-child{color:var(--muted);width:34px}
    .bill-items tbody tr:hover{background:var(--line-2)}
    /* Rich invoice detail */
    .bill-badge.dot::before{content:'';display:inline-block;width:7px;height:7px;border-radius:50%;background:currentColor;margin-right:6px;vertical-align:middle}
    .inv-cust{display:flex;flex-wrap:wrap;gap:22px;align-items:center}
    .inv-cust-main{display:flex;gap:14px;align-items:center;min-width:220px;flex:1}
    .inv-avatar{width:50px;height:50px;border-radius:50%;background:rgba(59,130,246,.12);color:#2563eb;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;flex:none}
    .inv-cust-main .nm{font-weight:700;font-size:16px}
    .inv-cust-main .sub{font-size:12.5px;color:var(--muted);margin-top:2px;line-height:1.7}
    .inv-cmeta{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:16px 26px;flex:2;min-width:260px}
    .inv-cmeta span{display:block;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
    .inv-cmeta strong{display:block;font-size:13.5px;margin-top:4px}
    .inv-cmeta small{display:block;color:var(--muted);font-size:11px;margin-top:1px}
    .inv-grid{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:18px;align-items:start}
    @media(max-width:900px){.inv-grid{grid-template-columns:1fr}}
    .inv-sum{padding:4px 2px}
    .inv-sum .r{display:flex;justify-content:space-between;gap:12px;padding:7px 0;font-size:14px}
    .inv-sum .r span{color:var(--muted)}
    .inv-sum .r.tot{border-top:1px solid var(--line);margin-top:4px;padding-top:11px;font-weight:700;font-size:15px}
    .inv-sum .r.tot span{color:inherit}
    .inv-sum .r .pos{color:var(--green,#059669);font-weight:600}
    .inv-bal{margin-top:14px;padding:15px 16px;border-radius:12px;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.22)}
    .inv-bal .lbl{font-size:12px;font-weight:600;color:#2563eb}
    .inv-bal b{display:block;font-size:25px;color:#2563eb;margin-top:3px;line-height:1.1}
    .inv-bal.clear{background:rgba(16,185,129,.08);border-color:rgba(16,185,129,.22)}
    .inv-bal.clear .lbl,.inv-bal.clear b{color:var(--green,#059669)}
    .two-col{display:grid;grid-template-columns:1fr 1fr;gap:18px}
    @media(max-width:760px){.two-col{grid-template-columns:1fr}}
    .act-pill{display:inline-flex;align-items:center;gap:6px;padding:3px 11px;border-radius:999px;font-size:12px;font-weight:600}
    .act-pill.green{background:rgba(16,185,129,.14);color:var(--green,#059669)}
    .act-pill.blue{background:rgba(59,130,246,.14);color:#2563eb}
    .ov-red{color:var(--red,#dc2626)}
    .muted-dash{color:var(--muted)}
</style>
@php
    if (! function_exists('billing_badge_class')) {
        function billing_badge_class(?string $status): string {
            return ['Paid'=>'paid','Partially Paid'=>'part','Sent'=>'sent','Unpaid'=>'unpaid','Draft'=>'draft',
                'Accepted'=>'accepted','Declined'=>'declined','Converted'=>'converted'][$status] ?? 'draft';
        }
    }
@endphp
