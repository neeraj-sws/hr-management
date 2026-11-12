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
    /* List look of the old CMS: bordered number button, solid status pill, coloured round action buttons. */
    .cms-no{display:inline-block;border:1px solid #0d6efd;color:#0d6efd;border-radius:6px;padding:9px 18px;font-weight:600;white-space:nowrap;text-decoration:none}
    .cms-no:hover{background:#0d6efd;color:#fff}
    .cms-pill{display:inline-block;min-width:96px;text-align:center;border-radius:8px;padding:6px 14px;font-size:12px;font-weight:600;color:#fff;background:#6c757d;white-space:nowrap}
    .cms-pill.sent{background:#ffa21d}.cms-pill.paid{background:#6fd943}.cms-pill.part{background:#3ec9d6}.cms-pill.unpaid{background:#ff3a6e}
    .cms-pill.draft{background:#6c757d}.cms-pill.accepted{background:#6fd943}.cms-pill.declined{background:#ff3a6e}.cms-pill.converted{background:#6f42c1}
    .cms-acts{display:flex;gap:8px;flex-wrap:nowrap}
    .cms-act{width:36px;height:36px;border-radius:8px;border:0;display:inline-flex;align-items:center;justify-content:center;color:#fff;cursor:pointer;text-decoration:none}
    .cms-act svg{width:16px;height:16px}
    .cms-act:disabled{opacity:.45;cursor:not-allowed}
    .cms-act.link{background:#ffa21d}.cms-act.copy{background:#6fd943}.cms-act.view{background:#3ec9d6}.cms-act.edit{background:#0d6efd}.cms-act.del{background:#ff3a6e}.cms-act.conv{background:#6f42c1}
    .cms-act:hover{filter:brightness(.92);color:#fff}
    .cms-late,.table td.cms-late{color:#ff3a6e}
    /* CMS invoice detail: steps, button row, the invoice card. */
    .cms-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;text-align:center;padding:26px 22px}
    .cms-step h6{margin:10px 0 8px;font-size:15px;font-weight:600}
    .cms-step p{margin:0 0 12px;font-size:12.5px;color:var(--muted);display:flex;gap:4px;align-items:center;justify-content:center}
    .cms-step-ic svg{width:28px;height:28px}
    .cms-b-primary,.cms-b-warning,.cms-b-info{color:#fff!important;border:0!important}
    .cms-b-primary{background:#0d6efd!important}.cms-b-warning{background:#ffa21d!important}.cms-b-info{background:#3ec9d6!important}
    .cms-b-primary:hover,.cms-b-warning:hover,.cms-b-info:hover{filter:brightness(.92)}
    .cms-btnrow{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}
    .cms-inv{padding:26px 28px}
    .cms-inv-head{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid var(--line);padding-bottom:14px;margin-bottom:12px}
    .cms-inv-head h4{margin:0;font-size:20px;font-weight:600}
    .cms-inv-dates{display:flex;justify-content:flex-end;gap:26px;font-size:12.5px;text-align:left}
    .cms-inv-parties{display:flex;justify-content:space-between;gap:20px;margin-top:14px;line-height:1.75}
    .cms-qr{width:126px;height:126px;flex:none}
    .cms-qr svg{width:100%;height:100%}
    .cms-inv-status{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:16px;line-height:1.9}
    .cms-inv-prod{margin:26px 0 10px}
    .cms-inv-table th{background:var(--line-2);font-size:13px;text-transform:none;letter-spacing:0;color:var(--text)}
    .cms-inv-table td,.cms-inv-table th{text-align:left;vertical-align:top}
    .table .cms-r,.cms-inv-table .cms-r{text-align:right}
    .cms-inv-table tfoot td{border-top:1px solid var(--line)}
    @media (max-width:640px){.cms-steps{grid-template-columns:1fr}.cms-inv{padding:18px 14px}.cms-inv-parties{flex-direction:column}}
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
    .inv-cust{display:flex;flex-wrap:wrap;gap:0;align-items:stretch}
    .inv-cust-main{display:flex;gap:14px;align-items:center;min-width:220px;flex:1;padding:4px 24px 4px 0}
    @media(min-width:820px){.inv-cust-main{border-right:1px solid var(--line)}}
    .inv-avatar{width:50px;height:50px;border-radius:50%;background:rgba(59,130,246,.12);color:#2563eb;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;flex:none}
    .inv-cust-main .nm{font-weight:700;font-size:16px}
    .inv-cust-main .sub{font-size:12.5px;color:var(--muted);margin-top:2px;line-height:1.7}
    .inv-cmeta{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:18px 0;flex:2.3;min-width:260px;padding:4px 0;align-content:center}
    .inv-cmeta > div{padding:0 24px;border-left:1px solid var(--line)}
    .inv-cmeta > div:first-child{border-left:0}
    .inv-cmeta > div:last-child{padding-right:0}
    .inv-cmeta span{display:block;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
    .inv-cmeta strong{display:block;font-size:13.5px;margin-top:5px}
    .inv-cmeta small{display:block;color:var(--muted);font-size:11px;margin-top:2px}
    .inv-grid{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:18px;align-items:start}
    @media(max-width:900px){.inv-grid{grid-template-columns:1fr}}
    .inv-sum{padding:2px 0}
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
    .inv-bal .lbl{display:inline-flex;align-items:center;gap:6px}
    .inv-bal-ic{width:40px;height:40px;border-radius:50%;background:var(--green,#059669);color:#fff;display:flex;align-items:center;justify-content:center;flex:none}
    .meta-dot::before{content:'';display:inline-block;width:7px;height:7px;border-radius:50%;background:currentColor;margin-right:6px;vertical-align:middle}
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
