# Go-live Runbook & Handover

## 1. Go-live runbook (production)
1. **Server**: follow `docs/DEPLOYMENT.md` §1–2 (PHP ≥ 8.2 with bcmath, pdo_mysql, mbstring, zip, gd; MySQL 8 / MariaDB 10.4+; HTTPS).
2. **Install**: `composer install --no-dev --optimize-autoloader`, `.env` from the template (APP_ENV=production, APP_DEBUG=false, HTTPS APP_URL, SMTP, `SESSION_SECURE_COOKIE=true`), `php artisan key:generate` (first install only), `php artisan migrate --force`, `php artisan db:seed --class=BaseSeeder --force`, `php artisan storage:link`, config/route/view cache, cron for `schedule:run`.
   - **Upgrading an existing Phase 1 installation**: take a backup, then `php artisan migrate --force`. Existing stock and history move to store `MAIN` and existing storekeepers are assigned to it automatically. Rehearse on a copy of the live database first (backlog B-34).
3. **Reference data** (Owner/Admin): Settings (company details, logo, PO owner threshold), users and roles (no shared accounts), stores and storekeeper assignment, cost codes (defaults are seeded), expense categories, suppliers.
4. **Data migration** — only what the brief allows (no bulk legacy migration):
   - Catalogue: *Stock & items → Import items* (CSV template).
   - Opening balances per store: *Receipts → Opening balances → template per store* after a physical count; all-or-nothing import.
   - Projects (screen), then per project: budgets per cost code, BOQ (*Import BOQ* CSV), milestones, staff assignment.
   - Open purchase orders, if any: re-enter as POs and have them approved.
5. **Checks before opening to users**: `php artisan cms:go-live-check` (no FAIL lines), `php artisan cms:reconcile` (all PASS), `php artisan cms:verify-audit`, take the first backup and run the restore drill (`DEPLOYMENT.md` §6). Record the results in `docs/UAT_EVIDENCE.md`.
6. **Cut-over**: announce the start date; stop paper issue vouchers that day; first week the storekeeper prints PDF vouchers for signature as before.

## 2. Secure handover checklist
| Item | How | Done |
|---|---|---|
| Source repository | Transfer ownership / add the owner as admin of the Git repository; tag the release | ☐ |
| Server, database and SMTP credentials | Handed over in person or via an encrypted password manager share — never by e-mail or chat in plain text | ☐ |
| `APP_KEY` | Sealed copy stored with the backups (needed for audit-chain verification after restore) | ☐ |
| Owner/Admin account | Owner sets their own password at first login; developer accounts deactivated | ☐ |
| Demo data | Absent from production (`cms:go-live-check` confirms) | ☐ |
| Backups | Location, retention (14 days default) and restore steps explained; first restore drill on the production host recorded | ☐ |
| Documents | README, ARCHITECTURE, SCHEMA, ROLE_PERMISSION_MATRIX, APPROVAL_STATE_MACHINE, PROCUREMENT, SECURITY, DEPLOYMENT, USER_MANUAL, TRAINING, UAT_TEST_CASES, UAT_EVIDENCE, CHANGELOG | ☐ |

## 3. Warranty (30 days from UAT sign-off)
- Defects (behaviour that differs from the documented scope or breaks a documented rule) are fixed free of charge within the warranty period. Report them with: what you did, what you expected, what happened, a screenshot, and the document number (e.g. IV-2026-00012).
- Priority: **P1** stock/money wrong or system down — response same working day; **P2** a role cannot complete its work — 2 working days; **P3** cosmetic/usability — scheduled.
- Not covered: new features or anything listed as out of scope (payroll, native mobile app, EFRIS, GPS, biometrics, AI, SMS/WhatsApp, bank integration, MFA) — these need an approved change order.

## 4. Open owner decisions
1. Name the Overall Manager(s) and assign storekeepers per store.
2. Confirm the PO owner-approval threshold (default UGX 20,000,000).
3. Confirm the substitute PM-step reviewer when a PM requests on their own project (default Owner/Admin).
4. Provide hosting, domain and SMTP mailbox.
5. Decide whether MFA is required (change order).

## 5. Acceptance
UAT sign-off is recorded in `docs/UAT_TEST_CASES.md` §5. Handover accepted by:

Owner: ____________________  Signature: ____________  Date: __________
Developer: ____________________  Signature: ____________  Date: __________
