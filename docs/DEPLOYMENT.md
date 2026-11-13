# Deployment, Backup & Restore

The target is one production domain on ordinary shared hosting (cPanel-style) or a small VPS. No paid third-party services are required.

## 1. Server requirements
- PHP **8.2+** with extensions: bcmath, ctype, fileinfo, mbstring, openssl, pdo_mysql, tokenizer, xml, zip.
- MySQL 8 or MariaDB 10.4+ (InnoDB, needed for row locks).
- Composer (or upload the `vendor/` folder built locally with `composer install --no-dev`).
- Cron access (one entry), HTTPS certificate (free AutoSSL / Let's Encrypt).
- **No Node.js build step is needed.** CSS is a static file in `public/css/app.css`.

## 2. First install
```bash
# 1. Upload the code (git clone or zip) OUTSIDE public_html, e.g. ~/cms
# 2. Point the domain document root to ~/cms/public (or symlink public_html -> ~/cms/public)
cd ~/cms
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```
Edit `.env`:
```
APP_NAME="Company CMS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://cms.example.co.ug
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
MAIL_MAILER=smtp           # hosting mailbox: password resets and approval alerts
MAIL_HOST=... MAIL_PORT=465 MAIL_USERNAME=... MAIL_PASSWORD=... MAIL_FROM_ADDRESS=no-reply@...
ADMIN_EMAIL=owner@company.co.ug
ADMIN_PASSWORD=             # leave empty to have a random temporary password printed once
COMPANY_NAME="..."
SESSION_SECURE_COOKIE=true
CMS_MAIL_ALERTS=true        # false = in-app notifications only
CMS_REMINDER_DAYS=2         # remind whoever must act after this many days
CMS_ESCALATION_DAYS=5       # also alert the Owner/Admin after this many days
```
Then:
```bash
php artisan migrate --force
php artisan db:seed --class=BaseSeeder --force   # reference data + first admin. Never run DemoSeeder in production.
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
chmod -R 775 storage bootstrap/cache
```
Cron (cPanel → Cron Jobs):
```
* * * * * cd ~/cms && php artisan schedule:run >> /dev/null 2>&1
```
The scheduler then runs:
| Command | When | Purpose |
|---|---|---|
| `cms:backup --keep=14` | daily 01:30 | database dump (§5) |
| `cms:verify-audit` | daily 02:30 | tamper check of the activity log; a failure is logged with the row id |
| `cms:reminders` | weekdays 07:00 | reminders for pending approvals/receipts; escalation to the Owner/Admin |
| `cms:budget-alerts` | 07:00 and 13:00 | warn (≥ 90 %) / over-budget alerts per project and cost code, once per level |

Run any of them by hand to check, e.g. `php artisan cms:verify-audit`.

## 3. Go-live data
1. The admin signs in, changes the temporary password, and fills in **Settings** (company name, address, logo, voucher footer).
2. Create users (temporary passwords; each user changes theirs at first sign-in). Name the **Overall Manager** and **Storekeeper** chosen by the owner.
3. Create projects, assign a PM and staff.
4. Create items (Stock & items → New item), then **Opening balances** from the physical count.

Bulk legacy migration is out of Phase 1 scope. Opening balances are entered once per item.

## 4. Updates
```bash
php artisan down
git pull   # or upload new files
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```
Always take a backup (`php artisan cms:backup`) before migrating.

## 5. Backup
- `php artisan cms:backup` creates `storage/app/backups/backup-YYYYMMDD-HHMMSS.zip`. It holds a `mysqldump --single-transaction` of the database plus uploaded expense proofs. The newest 14 are kept (`--keep=N` changes this).
- If `mysqldump` is not on PATH, set `MYSQLDUMP_PATH` in `.env`. If the host forbids running programs, use cPanel's daily backup or phpMyAdmin export instead, and record which method is used.
- **Off-site copy (required):** once a week, download the newest zip to the company's Google Drive or an external disk. A backup kept only on the same server is not a backup.

## 6. Restore (also the UAT A12 proof)
```bash
php artisan down
unzip storage/app/backups/backup-YYYYMMDD-HHMMSS.zip -d /tmp/restore
mysql -h localhost -u USER -p DB_NAME < /tmp/restore/db-YYYYMMDD-HHMMSS.sql
cp -r /tmp/restore/expense-proofs/* storage/app/private/expense-proofs/
php artisan up
```
**Restore drill for acceptance:** restore the latest backup into a separate empty database (e.g. `cms_restore_test`). Then compare row counts for `material_requests`, `material_issues`, `stock_movements` and `expenses` with production. Finally, open any voucher. Record the date and the counts in `UAT_TEST_CASES.md` A12.

## 7. Concurrency proof (UAT A8)
On the staging server (never production), create an empty database whose name contains `test`, then run:
```bash
DB_DATABASE=cms_concurrency_test php artisan cms:concurrency-test --workers=8
```
It launches 8 parallel processes that try to (1) take the last 5 units for 8 competing approved requests, and (2) over-issue a single approval of 10. All checks must print **PASS**. Save the output as UAT evidence.

## 8. Security checklist
- `APP_DEBUG=false`, HTTPS only, `SESSION_SECURE_COOKIE=true`.
- Document root is `public/` only. `.env`, `storage/` and `vendor/` are not web-accessible.
- Expense proofs live on the private disk and are served only after an authorization check.
- Login is rate-limited and there is no public registration. Signed-in users are limited to 300 requests per minute.
- Security headers (CSP, frame, nosniff, HSTS on HTTPS) are set by the application. See `docs/SECURITY.md` for the full control list.
- Keep a copy of `APP_KEY` with the backups (sealed): it is needed to verify the audit chain after a restore.
- Deactivate (don't delete) staff who leave.
