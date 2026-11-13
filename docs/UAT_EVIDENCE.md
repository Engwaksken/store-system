# UAT Evidence: MySQL/MariaDB runs (12 Nov 2026)

Environment: MariaDB 10.4.32 (XAMPP binaries, separate throwaway instance on port 3307), PHP 8.2.12, Windows 11, developer machine.
These runs cover A8 and A12 before hosting exists. **Repeat both on the staging/production host before sign-off**, following `docs/DEPLOYMENT.md` §6–7.

## A8: simultaneous issue cannot overspend (`php artisan cms:concurrency-test --workers=8`)
Eight separate PHP processes were released at the same instant.

**Scenario 1:** 8 different approved requests (5 units each) race for the last 5 units in stock.
```
worker 0: REFUSED WorkflowException: Scarce item: cannot issue 5.000; only 0.000 in stock.
worker 1: REFUSED ...   worker 2: REFUSED ...
worker 3: OK IV-2026-00001
worker 4..7: REFUSED WorkflowException: Scarce item: cannot issue 5.000; only 0.000 in stock.
```
**Scenario 2:** 8 workers each try to issue 4 against ONE request approved for 10, with plentiful stock.
```
worker 4: OK IV-2026-00002
worker 5: OK IV-2026-00003
other 6 workers: REFUSED WorkflowException: Plentiful item: cannot issue 4.000; only 2.000 approved quantity remains.
```
Checks:
```
PASS S1: exactly one of 8 competing issues succeeded
PASS S1: on hand for scarce item is 0 (got 0.000)
PASS S1: ledger sum equals on hand (0.000)
PASS S1: total issued equals opening stock 5 (5.000)
PASS S2: at most 2 x 4 issued against approval of 10 (successes: 2, issued 8.000)
PASS No negative balances anywhere
```

## Full automated suite on MariaDB
`php artisan test` with `DB_CONNECTION=mysql`: **49 passed (432 assertions)**. Confirmed by 30 tables being created in the `cms_phpunit_test` schema.

## A12: backup and restore drill
`php artisan cms:backup` (mysqldump) produced a 6 KB zip. It was restored into an empty database `cms_restore_test`:

| Table | Source rows | Restored rows |
|---|---:|---:|
| users | 4 | 4 |
| projects | 1 | 1 |
| material_requests | 9 | 9 |
| material_approvals | 18 | 18 |
| material_issues | 3 | 3 |
| material_issue_lines | 3 | 3 |
| stock_movements | 5 | 5 |
| inventory_balances | 2 | 2 |

The sum of `inventory_balances.on_hand` matches: **yes**.

---

# Phase 2–6 evidence (13 Nov 2026)
Environment: MariaDB 10.4.32 (XAMPP binaries, throwaway instance on port 3307), PHP 8.2.12, Windows 11.

## Full automated suite on MariaDB
`php artisan test` with `DB_CONNECTION=mysql`: **all tests pass** on both MariaDB and SQLite after every phase (Phase 2: 74, Phase 3: 85, Phase 4: 93, Phase 5: 106, Phase 6: see `UAT_TEST_CASES.md`).
Running on MariaDB caught two MySQL-only issues that SQLite does not: an auto-generated index name over MySQL's 64-character limit (fixed with an explicit name), and MariaDB re-stamping the first `TIMESTAMP` column on `UPDATE` (harmless for append-only tables; the audit-chain test restores the timestamp explicitly).

## Parallel issue race with the multi-store code (`cms:concurrency-test --workers=8`)
```
Scenario 1: 8 approved requests race for the last 5 units in one store
  worker 2: OK IV-2026-00001
  workers 0,1,3–7: REFUSED … cannot issue 5.000; only 0.000 in stock at this store.
Scenario 2: 8 workers issue 4 each against one request approved for 10
  worker 0: OK IV-2026-00002     worker 1: OK IV-2026-00003
  workers 2–7: REFUSED … only 2.000 approved quantity remains.
PASS S1: exactly one of 8 competing issues succeeded
PASS S1: on hand for scarce item is 0 (got 0.000)
PASS S1: ledger sum equals on hand (0.000)
PASS S1: total issued equals opening stock 5 (5.000)
PASS S2: at most 2 x 4 issued against approval of 10 (successes: 2, issued 8.000)
PASS No negative balances anywhere
```
Afterwards, on the same raced database:
```
$ php artisan cms:reconcile        → all 8 checks PASS, "All books reconcile."
$ php artisan cms:verify-audit     → "Audit chain intact: 40 rows verified."
```
The audit chain stayed linear while 8 processes wrote concurrently (writers serialize on `audit_chain_heads`).

## Still to do on the production host
- Phase 1 → Phase 2+ upgrade rehearsal on a copy of the live database (B-34).
- Backup/restore drill (A12) and `cms:go-live-check` with no FAIL lines.
- Repeat `cms:concurrency-test` on staging (never on the production database: it runs `migrate:fresh`).

