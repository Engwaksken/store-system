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
