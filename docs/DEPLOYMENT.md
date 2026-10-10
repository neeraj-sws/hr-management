# SWS HR — Go-live guide (fresh setup, start to end)

Three parts ship separately but talk to one server:
1. **Web + API** (Laravel) — the core; everything else needs it.
2. **Mobile app** (Expo/React Native) — built and installed on phones.
3. **Desktop app** (Windows) — built into an installer for employee PCs.

Do them in that order. Nothing here happens automatically — a person with server
access runs each step.

---

## 0. What you need first
- A server (Linux recommended: Ubuntu 22.04+). 2 vCPU / 4 GB RAM is fine to start.
- A domain, e.g. `hr.yourcompany.com`, pointed at the server's IP (DNS A record).
- An HTTPS certificate (free via Let's Encrypt). **Required** — mobile, desktop updates and secure cookies depend on it.
- SMTP details for outgoing email (host, port, username, password) — from your email provider or a service like Amazon SES / Postmark / your Google Workspace.
- For mobile push + builds: a free **Expo (expo.dev)** account.
- For the desktop installer: a **Windows build PC** with Python 3.11+ and (optional) Inno Setup 6.

---

## 1. Server software (one time)
Install: PHP 8.2+ with extensions `pdo_mysql, mbstring, openssl, fileinfo, gd, zip, bcmath, curl`, Composer, MySQL/MariaDB 10.4+, and Nginx.

```bash
sudo apt update && sudo apt install -y php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-gd php8.2-bcmath unzip nginx mariadb-server
```

Install Composer from https://getcomposer.org. (Node.js is **not** needed — the web UI is served as static CSS/JS.)

---

## 2. Database (one time)
Create an empty database and a dedicated user (never use `root` in production):

```sql
CREATE DATABASE sws_hr CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sws_user'@'localhost' IDENTIFIED BY 'A-strong-unique-password';
GRANT ALL PRIVILEGES ON sws_hr.* TO 'sws_user'@'localhost';
FLUSH PRIVILEGES;
```

---

## 3. Get the code
```bash
cd /var/www
git clone <your-repo-url> sws-hr
cd sws-hr
git checkout main
```
(If there is no git remote, copy the project folder to `/var/www/sws-hr`.)

---

## 4. Configure `.env`
```bash
cp .env.example .env
```
Edit `.env` and set at least:

| Key | Value |
|---|---|
| `APP_NAME` | "SWS HR" |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://hr.yourcompany.com` |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` |
| `DB_DATABASE` | `sws_hr` |
| `DB_USERNAME` / `DB_PASSWORD` | `sws_user` / the password from step 2 |
| `SESSION_DRIVER` | `database` |
| `SESSION_SECURE_COOKIE` | `true` |
| `QUEUE_CONNECTION` | `database` |
| `CACHE_STORE` | `database` |
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` / `MAIL_ENCRYPTION` | your SMTP details |
| `MAIL_FROM_ADDRESS` | `hr@yourcompany.com` |
| `SEED_USER_PASSWORD` | a strong value — only used if you seed demo users (don't on real data) |
| `EXPO_PUSH_ENABLED` | `true` after the mobile app is built (step 9); else `false` |
| `DESKTOP_LATEST_VERSION`, `DESKTOP_DOWNLOAD_URL`, `DESKTOP_SHA256` | fill after the desktop installer is hosted (step 10) |

---

## 5. Install & initialise
```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=DatabaseSeeder --force
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```
- `DatabaseSeeder` creates roles, permissions, leave types and **one Master Admin** (`admin@example.com` with `SEED_USER_PASSWORD`). Run it only on the first deploy.
- Do **not** run `DemoDataSeeder` / `HrmsExtensionsDemoSeeder` on production — those are fake demo data.
- File permissions: `storage/` and `bootstrap/cache/` must be writable by the web user:
```bash
sudo chown -R www-data:www-data storage bootstrap/cache
```

---

## 6. Web server + HTTPS
Point Nginx at `public/` and get a certificate.

```nginx
server {
    server_name hr.yourcompany.com;
    root /var/www/sws-hr/public;
    index index.php;
    client_max_body_size 20M;   # receipts, documents, screenshots
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```
Then:
```bash
sudo certbot --nginx -d hr.yourcompany.com
```
Certbot adds the HTTPS (443) block and auto-renews. Confirm `https://hr.yourcompany.com/login` opens.

---

## 7. Background jobs (REQUIRED — the app is half-dead without these)
Without them these stop: email/notification delivery, closing forgotten check-outs, nightly compliance checks, daily-status & overdue-task reminders, birthday/anniversary wishes, leave accrual, screenshot clean-up, expired-token pruning.

**Queue worker** — as a systemd service `/etc/systemd/system/sws-worker.service`:
```ini
[Unit]
Description=SWS HR queue worker
After=network.target
[Service]
User=www-data
WorkingDirectory=/var/www/sws-hr
ExecStart=/usr/bin/php artisan queue:work --tries=3 --max-time=3600
Restart=always
[Install]
WantedBy=multi-user.target
```
```bash
sudo systemctl enable --now sws-worker
```

**Scheduler** — cron, every minute (`sudo crontab -e -u www-data`):
```bash
* * * * * cd /var/www/sws-hr && php artisan schedule:run >> /dev/null 2>&1
```

---

## 8. Backups & security (before real data goes in)
- **Daily DB backup**, kept off the server for 30 days:
```bash
mysqldump --single-transaction sws_hr | gzip > /backups/sws_hr_$(date +\%F).sql.gz
```
- Back up `storage/app` too (documents, receipts, resumes, payslips, screenshots — private files).
- **Test a restore once** before go-live.
- Sign in as the seeded admin and immediately **change its password**; create the real HR/Accounts/Manager users; remove any `*@example.com` demo accounts.
- Screen capture / app-usage / window-title tracking stay **off** until an admin turns them on per employee, **with written notice to staff** (legally required in many places).

---

## 9. Mobile app (build, install, push)
On your dev machine, in `mobile/`:
```bash
npm install -g eas-cli
eas login
eas init
```
`eas init` writes `extra.eas.projectId` into `mobile/app.json`. Then:
1. Set `extra.apiUrl` in `mobile/app.json` to `https://hr.yourcompany.com`.
2. Build:
```bash
eas build -p android
```
(and `eas build -p ios` if you need iPhones — needs an Apple Developer account.)
3. Install the build on phones (Android: the APK/AAB link EAS gives; iOS: TestFlight / App Store).
4. On the server set `EXPO_PUSH_ENABLED=true` in `.env`, then `php artisan config:cache`.

Note: push notifications do **not** work in Expo Go on Android — they need this real build. Expo Go is only for testing during development.

---

## 10. Desktop app (build, installer, distribute, auto-update)
On a Windows build PC (Python 3.11+):
```bash
cd python
build-desktop.bat
```
This produces `python\dist\SWS HR\SWS HR.exe` and, if **Inno Setup 6** is installed (https://jrsoftware.org/isdl.php), `python\dist\SWS-HR-Setup-<version>.exe`. It prints `DESKTOP_LATEST_VERSION` and `DESKTOP_SHA256`.
1. (Recommended) Sign the installer with a code-signing certificate so Windows SmartScreen does not warn.
2. Upload the installer to an **https** URL (your site or cloud storage).
3. On the server set `DESKTOP_LATEST_VERSION`, `DESKTOP_DOWNLOAD_URL` (the https link) and `DESKTOP_SHA256` in `.env`, then `php artisan config:cache`.
4. Employees install it once (per-user, no admin rights). On the next release, bump `VERSION` in `python/sws_desktop/version.py`, rebuild, re-host, update the three `.env` values — running apps offer the update automatically and refuse any installer whose checksum doesn't match.

---

## 11. Go-live smoke test (do this before announcing)
Sign in on the web as admin and run one real employee end-to-end:
create employee → assign team & manager → set salary → employee logs in (web/phone/desktop) → check-in → task + timer → break → daily status → check-out → apply leave/OT → manager approves → run payroll → review → lock → record payment → payslip → check it all appears in Reports and Audit.
Also: send a test email (trigger any approval and confirm it arrives), and open the app on one real phone.

---

## 12. Ongoing
- Deploys after the first: `git pull` → `composer install --no-dev -o` → `php artisan migrate --force` → `php artisan config:cache route:cache view:cache` → `sudo systemctl restart sws-worker`.
- Watch disk (screenshots/app-usage grow); the nightly purge keeps only the retention window you set.
- Keep PHP, MariaDB and the OS patched.
