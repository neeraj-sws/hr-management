# Billing / Sales + Timesheet + Client Portal — Deploy & Flow Check

## 1) Deploy (live) — isi order me

1. **Code zip** — `billing-update-FINAL.zip` extract karke live project me merge/overwrite karo
   (folders: `config/`, `routes/`, `app/`, `resources/`).
2. **Data SQL** — `live-billing-DATA-replace.sql` → phpMyAdmin → SQL tab → Go (ek baar).
   - (Schema/tables pehle se bani hui hain, to `live-billing-timesheet.sql` dobara MAT chalao.)
3. **Cache clear** — browser me: `https://hrms.sohamsolution.com/clear-cache`
   → "Cache cleared: …" dikhe.

Agar kisi user ko billing nahi khul raha (403): usko `hr.manage`/`payroll.manage` permission chahiye
(Master Admin ke paas hai). Warna phpMyAdmin me:
```sql
INSERT IGNORE INTO model_has_permissions (permission_id, model_type, model_id)
SELECT p.id, 'App\\Models\\User', u.id
FROM users u
JOIN permissions p ON p.name IN ('hr.manage','payroll.manage') AND p.guard_name='web'
WHERE u.name = 'USER_KA_NAAM';
```
Phir `/clear-cache`.

---

## 2) Flow check — Sales (billing)

**Sales → Customers**
- [ ] List me 52 customers dikhein (city/state, invoice count).
- [ ] "Add customer" → naam + email daal ke Save → list me aaya.

**Sales → Invoices**
- [ ] Default filter **2026-27** (current FY) ho, 18 invoices dikhein.
- [ ] Upar stats: Invoices / Billed / Received / Outstanding.
- [ ] Financial year dropdown: old → new years.
- [ ] Kisi invoice pe **Open** → detail page:
  - [ ] Header meta (Customer / Status / Issue / Due / Place of supply / GSTIN) — proper spacing.
  - [ ] Items table + Sub-total / CGST / SGST / Total / Paid / Due.
  - [ ] **Print / PDF** button → naya tab me print view khule.

**Naya invoice**
- [ ] "New invoice" → customer select, item name + qty + price, tax chuno.
- [ ] Live sub-total/tax/total update ho.
- [ ] **Save invoice** → detail page khule, number `SWS/2026-27/000XX` (next number).

**Record payment**
- [ ] Invoice detail → "Record payment" → amount (default = due) → Save payment.
- [ ] Status badal jaye (Sent → Partially Paid / Paid), Due kam ho, payment row dikhe.

**Estimate**
- [ ] Sales → Estimates → "New estimate" → customer + item → Save → `EST/2026-27/000XX`.
- [ ] Detail pe **Convert to invoice** → confirm → naya invoice ban ke uski detail khule.

**Baaki**
- [ ] Credit notes → Add → invoice + amount → save; linked invoice ka Due kam ho.
- [ ] Revenue → Add entry.
- [ ] Products / Taxes → Add item / tax.

---

## 3) Flow check — Timesheet + Client Portal

**Staff: Work → Timesheets**
- [ ] Project chuno (jiska client set hai) → month chuno.
- [ ] Lines: task-timer se auto aati hain; "Add line" se manual; "Import Excel" se purani sheet.
- [ ] Carried-in / Worked / Billable / Rate / Amount pills.
- [ ] "Rate & settings" → hourly rate + carried-in minutes set.

**Work → Clients**
- [ ] Client banao; "Portal login" → naam + email + password (10+ char, ek letter).

**Client portal (client login se)**
- [ ] `/login` pe client email+password → sirf apna portal (Projects / Timesheet / Invoices).
- [ ] Timesheet → kisi line pe **Object** (reason + proposed minutes) → "Open objections 1".
- [ ] Jab tak objection open hai, **Approve** block rahe.

**Staff: objection ka jawab**
- [ ] Work → Timesheets → us line pe objection → "Answer" → accept/keep → client ko dikhe.

**Client: approve → invoice**
- [ ] Objection resolve hone pe client **Approve this month**.
- [ ] Staff side: us month ka **Create invoice** → billable hours × rate ka invoice bane.

---

## 4) Agar kuch galat lage
- Screenshot bhej do (us page + galat element pe nishaan).
- Ya `/clear-cache` ek baar aur chala ke dekho (deploy ke baad zaroori).
