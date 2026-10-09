# Going live — SWS HR

Checklist for moving from the local UAT setup to a real server. Nothing here is
done automatically; each step needs the server owner.

## 1. Server
- PHP 8.2+ (extensions: pdo_mysql, mbstring, openssl, fileinfo, gd, zip), Composer, MySQL/MariaDB 10.4+, a web server (Nginx/Apache) pointing at `public/`.
- HTTPS certificate (required: the mobile app, desktop updates and secure cookies depend on it).

## 2. `.env`
Copy `.env.example` and set at least:

| Setting | Value |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | `https://hr.your-company.example` |
| `APP_KEY` | `php artisan key:generate` (once) |
| `DB_*` | the production database and a dedicated DB user (not `root`) |
| `SESSION_SECURE_COOKIE` | `true` |
| `QUEUE_CONNECTION` | `database` (needs the worker below) |
| `MAIL_*` | your SMTP details (`MAIL_MAILER=smtp`); locally it is `log` |
| `SEED_USER_PASSWORD` | only if you run the demo seeder — never on production data |
| `EXPO_PUSH_ENABLED` / `EXPO_ACCESS_TOKEN` | `true` once the mobile app is built with an EAS project (step 6) |
| `DESKTOP_LATEST_VERSION`, `DESKTOP_DOWNLOAD_URL`, `DESKTOP_SHA256` | the desktop installer currently offered (step 7) |

Then:
```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=DatabaseSeeder --force   # roles, permissions, leave types; first run only
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## 3. Background jobs (required)
Without these, these features stop: notifications/e-mails (queue), closing forgotten
check-outs, nightly compliance checks, daily-status reminders, birthday/anniversary
wishes, leave accrual and screenshot clean-up.

- **Queue worker** (keep running with Supervisor / systemd / NSSM on Windows):
  `php artisan queue:work --tries=3 --max-time=3600`
- **Scheduler** — cron every minute:
  `* * * * * cd /path/to/sws-hr && php artisan schedule:run >> /dev/null 2>&1`
  (Windows: Task Scheduler, every 1 minute, `php artisan schedule:run`).

Scheduled jobs (`routes/console.php`): leave accrual (1st of month 00:15), celebrations 09:00,
close stale sessions every 15 min, screenshot purge 02:30, compliance assessment 03:00,
daily-status reminder weekdays 18:15.

## 4. Backups
- Daily database dump (e.g. `mysqldump --single-transaction`) kept off the server, 30 days.
- Back up `storage/app` (documents, receipts, resumes, payslips, screenshots) — it is private storage, not `public/`.
- Test a restore once before go-live.

## 5. Security checks before go-live
- `APP_DEBUG=false`, HTTPS only, `.env` not web-readable.
- Change every seeded password; remove demo users (`*@example.com`).
- Screen capture stays **off** unless the company enables it under Settings with a written policy; employees must acknowledge it in the desktop app.

## 6. Mobile app
1. `npm i -g eas-cli`, `eas login`, `eas init` in `mobile/` — this writes `extra.eas.projectId` into `app.json`.
2. Set `extra.apiUrl` in `mobile/app.json` to the HTTPS server.
3. `eas build -p android` (and `-p ios`); install the build on phones. Push notifications do **not** work in Expo Go on Android — they need this build.
4. On the server set `EXPO_PUSH_ENABLED=true` (and `EXPO_ACCESS_TOKEN` if you enabled enhanced push security).

## 7. Desktop app
1. On a Windows build PC: `python\build-desktop.bat` → `python\dist\SWS HR\SWS HR.exe`.
2. Install [Inno Setup 6](https://jrsoftware.org/isdl.php) and run the script again → `python\dist\SWS-HR-Setup-<version>.exe` (per-user, no admin rights). It prints `DESKTOP_LATEST_VERSION` and `DESKTOP_SHA256`.
3. Optional but recommended: sign the installer with your code-signing certificate so Windows SmartScreen does not warn.
4. Upload the installer to an **https** URL, put the three `DESKTOP_*` values in the server `.env`. Running apps offer the update on next start; an installer whose SHA-256 does not match is deleted, never run.
5. For each release bump `VERSION` in `python/sws_desktop/version.py`.

## 8. Load check (not done locally)
Before rollout to everyone, run a load test (e.g. k6 or JMeter) with the expected head-count:
heartbeat every minute per open desktop app, check-in peak at shift start, payroll calculation for all employees.
