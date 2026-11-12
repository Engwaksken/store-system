# Prioritised Backlog & Status (12 Nov 2026)

Legend: ✅ done and covered by automated tests · 🟡 done, needs owner or staging verification · ⬜ open · ⛔ deferred to Phase 2 (not started, not promised).

## P0: correctness and controls
| ID | Item | Status | Evidence |
|---|---|---|---|
| B-01 | Repository audit and frozen scope | ✅ | `docs/MVP_SCOPE.md` |
| B-02 | Schema: 21 domain tables, decimals, FKs, unique document numbers | ✅ | `docs/SCHEMA.md`, migrations |
| B-03 | Auth: login, logout, rate limit, password reset e-mail, forced change, deactivation | ✅ | `AccessControlTest` |
| B-04 | Six roles plus server-side gates for every route | ✅ | `docs/ROLE_PERMISSION_MATRIX.md`, `AccessControlTest` |
| B-05 | Projects CRUD, unique code, budget, PM, staff assignment, scoped visibility | ✅ | `test_project_visibility_is_role_scoped` |
| B-06 | Catalog: categories, units, items, min level | ✅ | smoke test |
| B-07 | Opening balances (once per item), receipts, weighted average cost, append-only ledger | ✅ | `IssuanceAndStockTest` |
| B-08 | Adjustments with reason; never negative | ✅ | `test_adjustment_cannot_make_stock_negative_and_needs_reason` |
| B-09 | Requests: draft, submit, PM decision, Overall decision, revise, cancel; immutable decisions | ✅ | `ApprovalWorkflowTest` (18 tests) |
| B-10 | Separation of duties (PM ≠ requester, Overall ≠ requester and ≠ PM approver, storekeeper only issues) | ✅ | same |
| B-11 | Race-safe partial issuance (transaction, row locks, re-validation), voucher, acknowledgment | ✅ / 🟡 | Sequential tests ✅. **Parallel MySQL proof pending:** run `cms:concurrency-test` on staging |
| B-12 | Expenses with proof upload (private disk), scoped access | ✅ | `test_expense_access_is_permission_controlled_and_scoped` |
| B-13 | Cost separation: materials issued vs cash expenses, no double count | ✅ | `test_store_purchases_are_not_counted_as_project_cost` |

## P1: reporting and handover
| ID | Item | Status | Evidence |
|---|---|---|---|
| B-14 | Owner dashboard (queues, low stock, budget vs cost) | ✅ | smoke test |
| B-15 | Six reports with print view and CSV (formula-injection safe) | ✅ | smoke test, CSV assertion |
| B-16 | Activity log | ✅ | |
| B-17 | Demo seed for UAT and training | ✅ | `DemoSeeder`, `PageSmokeTest` |
| B-18 | Daily backup command plus schedule | ✅ / 🟡 | Zip and retention tested (SQLite). mysqldump path to be verified on the host |
| B-19 | Restore drill | ⬜ | On staging, per `DEPLOYMENT.md` §6 |
| B-20 | Production deploy to client domain | ⬜ | Needs hosting account and domain from owner |
| B-21 | Training session plus quick manual | 🟡 | `docs/USER_MANUAL.md` written; session not held |
| B-22 | Owner UAT sign-off | ⬜ | `docs/UAT_TEST_CASES.md` |

## P2: polish within agreed scope
| ID | Item | Status |
|---|---|---|
| B-23 | Logo on voucher header | ✅ (needs `php artisan storage:link` on server) |
| B-24 | Dynamic "add line" button on request/receipt forms (currently 6–8 fixed rows) | ⬜ |
| B-25 | E-mail notification to approver on submit (uses existing SMTP; no SMS) | ⬜ |
| B-26 | CSV import template for opening balances (manual entry today) | ⬜ |

## Owner decisions still open (defaults in effect, see MVP_SCOPE §5)
1. Name the Overall Manager and the primary Storekeeper (D1).
2. Confirm the substitute PM-step reviewer when a PM requests on their own project (default: Owner/Admin, D2).
3. Provide the hosting account and domain, project list, and opening stock count.

## Deferred: Phase 2, explicitly excluded
Multiple stores and transfers · purchasing/RFQ/PO · supplier balances and portal · BOQ · payroll · equipment · native mobile app · offline sync · barcode/RFID · SMS/WhatsApp · accounting integration · multi-company · advanced analytics · bulk legacy migration.
