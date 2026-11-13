# Construction Store & Project CMS (Phase 1 MVP)

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
Demo logins (password `Demo12345`): owner@, overall@, pm1@, pm2@, store@ (main store), sitestore@ (Kira site store), accounts@, staff1@, staff2@ `demo.test`.

## Tests
```bash
php artisan test                                         # feature tests (SQLite in-memory)
DB_DATABASE=cms_concurrency_test php artisan cms:concurrency-test   # MySQL parallel-issue proof (staging only)
```

## Documents
| File | Purpose |
|---|---|
| `docs/MVP_SCOPE.md` | Frozen scope, repo audit, design defaults (D1–D11) |
| `docs/ROLE_PERMISSION_MATRIX.md` | Who can do what (enforced server-side) |
| `docs/APPROVAL_STATE_MACHINE.md` | Request states, guards, locking |
| `docs/SCHEMA.md` | Tables, invariants, cost formula |
| `docs/UAT_TEST_CASES.md` | Acceptance criteria → tests → manual scripts |
| `docs/UAT_EVIDENCE.md` | MariaDB race test, full suite on MariaDB, backup/restore drill |
| `docs/BACKLOG.md` | Prioritised backlog, done vs pending vs deferred |
| `docs/DEPLOYMENT.md` | Shared-hosting deploy, cron, backup & restore |
| `docs/USER_MANUAL.md` | Quick manual per role |
| `CHANGELOG.md` | Changes per phase |

## Where the rules live
- `app/Services/RequestWorkflow.php`: approval state machine and separation of duties
- `app/Services/IssueService.php`: transaction + `lockForUpdate` issuance, voucher, acknowledgment
- `app/Services/StockService.php`: receipts, opening balances, adjustments, weighted average cost (per store)
- `app/Services/StockTransferService.php`, `StockReturnService.php`, `StockCountService.php`: transfers, returns, counts
- `app/Models/Store.php` (`operableBy`, `assertOperable`): which stores a user may move stock in
- `app/Support/Idempotency.php`: duplicate-post protection
- `app/Services/ProjectCostService.php`: materials issued + cash expenses (no double count)
- `app/Providers/AppServiceProvider.php`: all authorization gates
