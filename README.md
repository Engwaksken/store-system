# Construction Store, Procurement & Project CMS

Laravel 12 web app for a construction company: projects, a central store plus project site stores (transfers, returns, stock counts), two-level material approvals, controlled race-safe issuing, project expenses and reports. Scope: see `docs/MVP_SCOPE.md`.

## Quick start (local)
```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite          # or configure MySQL in .env
php artisan migrate --seed              # base data + DEMO data (non-production only)
php artisan storage:link
php artisan serve                       # http://127.0.0.1:8000
```
Demo logins (password `Demo12345`): owner@, overall@, pm1@, pm2@, store@ (main store), sitestore@ (Kira site store), accounts@, buyer@ (procurement), auditor@ (read-only), staff1@, staff2@ `demo.test`.

## Tests
```bash
php artisan test                                         # feature tests (SQLite in-memory)
DB_DATABASE=cms_concurrency_test php artisan cms:concurrency-test   # MySQL parallel-issue proof (staging only)
php artisan cms:reconcile                                # books reconcile (read-only)
php artisan cms:verify-audit                             # audit chain intact (read-only)
php artisan cms:go-live-check                            # production readiness
```

## Documents
| File | Purpose |
|---|---|
| `docs/ARCHITECTURE.md` | Architecture diagram, ERD, request state diagram, scheduled jobs |
| `docs/MVP_SCOPE.md` | Phase 1 scope, repo audit, design defaults (D1–D11) |
| `docs/ROLE_PERMISSION_MATRIX.md` | Who can do what (enforced server-side) |
| `docs/APPROVAL_STATE_MACHINE.md` | Request states, guards, locking |
| `docs/SCHEMA.md` | Tables, invariants, cost formula |
| `docs/UAT_TEST_CASES.md` | Required scenarios → tests, manual scripts, sign-off |
| `docs/UAT_EVIDENCE.md` | MariaDB race test, full suite on MariaDB, backup/restore drill |
| `docs/BACKLOG.md` | Prioritised backlog, done vs pending vs deferred |
| `docs/DEPLOYMENT.md` | Shared-hosting deploy, cron, backup & restore |
| `docs/PROCUREMENT.md` | Purchasing flow, approval policy, three-way match |
| `docs/SECURITY.md` | Every security/integrity control and its test |
| `docs/USER_MANUAL.md` | Quick manual per role |
| `docs/TRAINING.md` | Training sessions per role |
| `docs/HANDOVER.md` | Go-live runbook, data migration, secure handover, warranty |
| `CHANGELOG.md` | Changes per phase |

## Where the rules live
- `app/Services/RequestWorkflow.php`: approval state machine and separation of duties
- `app/Services/IssueService.php`: transaction + `lockForUpdate` issuance, voucher, acknowledgment
- `app/Services/StockService.php`: receipts, opening balances, adjustments, weighted average cost (per store)
- `app/Services/StockTransferService.php`, `StockReturnService.php`, `StockCountService.php`: transfers, returns, counts
- `app/Models/Store.php` (`operableBy`, `assertOperable`): which stores a user may move stock in
- `app/Support/Idempotency.php`: duplicate-post protection
- `app/Services/PurchasingService.php`, `GoodsReceiptService.php`, `PurchaseInvoiceService.php`: PR → PO → GRN → invoice → payment
- `app/Services/ProjectCostService.php`: materials issued + cash expenses (no double count)
- `app/Providers/AppServiceProvider.php`: all authorization gates
