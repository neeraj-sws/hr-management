# Google Sheets sync

SWS HR can send attendance (and optionally leave, overtime and tasks) to a Google
Sheet. The sync runs every 30 minutes (`php artisan sheets:sync`, scheduled in
`routes/console.php`). Each run sends yesterday and today. Rows are updated in
place by key, so a late check-out or an approved correction replaces the earlier
row instead of adding a new one.

No Google Cloud project or service account is needed. A small Apps Script inside
the sheet receives the rows.

## 1. Create the sheet and the script

1. Create a Google Sheet (for example "SWS HR Attendance").
2. In the sheet open **Extensions → Apps Script**, delete the sample code and paste
   the script below.
3. Change `SECRET` to a long random value (at least 12 characters) and keep it for step 3.
4. Click **Save**.

```javascript
// SWS HR → Google Sheets receiver. One tab per report; rows are upserted by key.
const SECRET = 'change-me-to-a-long-random-value';

function doPost(e) {
  const lock = LockService.getScriptLock();
  lock.waitLock(30000);
  try {
    const data = JSON.parse(e.postData.contents);
    if (data.secret !== SECRET) return reply({ ok: false, error: 'Wrong secret' });

    const book = SpreadsheetApp.getActiveSpreadsheet();
    const sheet = book.getSheetByName(data.sheet) || book.insertSheet(data.sheet);
    const columns = data.columns;

    // Header row: written once, extended if new columns appear.
    let header = sheet.getLastRow() > 0 ? sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0] : [];
    columns.forEach(c => { if (header.indexOf(c) === -1) header.push(c); });
    sheet.getRange(1, 1, 1, header.length).setValues([header]).setFontWeight('bold');
    sheet.setFrozenRows(1);

    const keyIdx = data.key_columns.map(k => header.indexOf(k));
    const keyOf = row => keyIdx.map(i => String(row[i])).join('|');
    const lastRow = sheet.getLastRow();
    const existing = lastRow > 1 ? sheet.getRange(2, 1, lastRow - 1, header.length).getValues() : [];
    const index = {};
    existing.forEach((row, i) => { index[keyOf(row)] = i + 2; });

    const appended = [];
    data.rows.forEach(values => {
      const row = header.map(h => { const i = columns.indexOf(h); return i === -1 ? '' : (values[i] === null ? '' : values[i]); });
      const at = index[keyOf(row)];
      if (at) sheet.getRange(at, 1, 1, header.length).setValues([row]);
      else appended.push(row);
    });
    if (appended.length) sheet.getRange(sheet.getLastRow() + 1, 1, appended.length, header.length).setValues(appended);

    return reply({ ok: true, updated: data.rows.length - appended.length, added: appended.length });
  } catch (err) {
    return reply({ ok: false, error: String(err) });
  } finally {
    lock.releaseLock();
  }
}

function reply(body) {
  return ContentService.createTextOutput(JSON.stringify(body)).setMimeType(ContentService.MimeType.JSON);
}
```

## 2. Deploy it as a web app

1. Click **Deploy → New deployment**, choose the type **Web app**.
2. Set **Execute as: Me** and **Who has access: Anyone**. The shared secret is
   what protects it, so nobody without the secret can write to the sheet.
3. Click **Deploy**, allow the permissions, and copy the **Web app URL**
   (`https://script.google.com/macros/s/…/exec`).

If you change the script later, use **Deploy → Manage deployments → Edit → New
version** so that the URL stays the same.

## 3. Connect SWS HR

1. Go to **Settings → Google Sheets** (Master Admin or HR with `policies.manage`).
2. Paste the web-app URL, enter the same secret, tick the reports to send, tick
   **Sync automatically** and click **Save**.
3. Click **Send now** to send a date range immediately (up to two months at a
   time, for example to back-fill the current month). The result of the last sync
   is shown on the same page.

The sync runs with the permissions of the person who saved the settings, so they
must be able to view the reports that are ticked. If that person is deactivated,
save the settings again as another administrator.

## Columns

Attendance rows are keyed by `employee_code` + `work_date` and include check-in and
check-out time, active, idle and break time, screen time, late and early minutes,
day status and the check-in/check-out IP addresses. Leave, overtime and task rows
are keyed by their `id`.

## Server requirements

The scheduler must be running (`* * * * * php artisan schedule:run`), as described
in `docs/DEPLOYMENT.md`. The server needs outbound HTTPS access to
`script.google.com`.
