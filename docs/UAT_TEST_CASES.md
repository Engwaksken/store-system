# UAT Test Cases

Automated coverage: `php artisan test` (49 tests, 432 assertions; last run 12 Nov 2026, all passing on SQLite in-memory).
Manual UAT uses the demo data (`php artisan migrate --seed` in a non-production environment). All demo accounts use password `Demo12345`.

| Demo account | Role |
|---|---|
| owner@demo.test | Owner / Admin |
| overall@demo.test | Overall Manager |
| pm1@demo.test / pm2@demo.test | Project Manager (PRJ-001 / PRJ-002) |
| store@demo.test | Storekeeper |
| accounts@demo.test | Accountant |
| staff1@demo.test / staff2@demo.test | Staff (PRJ-001 / PRJ-002) |

## Acceptance criteria from the brief → evidence

| # | Brief acceptance criterion | Automated test | Manual UAT step | Result |
|---|---|---|---|---|
| A1 | Staff not assigned to a project cannot request for it | `ApprovalWorkflowTest::test_staff_not_assigned_to_project_cannot_request_for_it`, `..._direct_http_post_is_forbidden` | Log in as staff2 → New request → PRJ-001 is not in the list | ✅ auto |
| A2 | Wrong PM cannot approve | `test_wrong_pm_cannot_approve` | pm2 opens a PRJ-001 request → no decision panel; a POST returns 403 | ✅ auto |
| A3 | PM reject blocks overall approval | `test_pm_reject_blocks_overall_approval_and_issue` | pm1 rejects → overall sees no decision panel | ✅ auto |
| A4 | Overall reject blocks issue | `test_overall_reject_blocks_issue` | overall rejects → request not in storekeeper queue | ✅ auto |
| A5 | Storekeeper cannot issue until both stages are complete | `test_storekeeper_cannot_issue_until_both_stages_complete` | store@ sees only OVERALL_APPROVED / PARTIALLY_ISSUED requests | ✅ auto |
| A6 | No self-approval of both stages | `test_pm_as_requester_cannot_self_approve_admin_substitutes_and_overall_stays_independent`, `test_approvers_cannot_issue_and_storekeeper_cannot_approve` | pm1 raises a request on PRJ-001 → pm1 cannot approve it; owner approves as substitute | ✅ auto |
| A7 | Approved 10 with 6 available: issue ≤ 6, remainder pending, later only the remaining 4, never above 10 | `IssuanceAndStockTest::test_approved_10_with_6_available_scenario` | See script S2 below | ✅ auto |
| A8 | Simultaneous issue of final units cannot overspend the balance or the approved allocation | Sequential: `test_final_units_cannot_be_issued_twice_to_competing_requests`. **True parallel test:** `php artisan cms:concurrency-test` (MySQL only) | Run the command on the staging MySQL and keep its output as evidence | ✅ Parallel run passed on MariaDB 10.4 (8 workers, 6/6 checks), see `UAT_EVIDENCE.md`. Repeat on staging host. |
| A9 | Receipt increments balance; issue decrements only on commit; ledger and voucher reconcile | `test_receipt_increments_balance_and_writes_ledger`, `test_approval_alone_does_not_decrement_stock`, `test_failed_multi_line_issue_rolls_back_everything`, `test_ledger_and_vouchers_reconcile_with_balances` | Reports → Stock movement ledger; balances match item pages | ✅ auto |
| A10 | Unauthorized direct HTTP requests are rejected regardless of hidden menus | `AccessControlTest::test_unauthorized_direct_requests_are_rejected_regardless_of_menus` (17 role/route pairs), `test_client_cannot_set_status_through_hidden_fields` | — | ✅ auto |
| A11 | Expense and project access is permission-controlled; issue valuation is not counted twice | `test_expense_access_is_permission_controlled_and_scoped`, `test_project_visibility_is_role_scoped`, `test_store_purchases_are_not_counted_as_project_cost` | Reports → Project budget vs cost | ✅ auto |
| A12 | Backup can be restored | — | Follow `docs/DEPLOYMENT.md` §Backup & restore on staging; record the date and the restored row counts | ✅ Local drill passed (row counts and balances match), see `UAT_EVIDENCE.md`. ☐ Repeat on production host. |
| A13 | Source, migration, demo seed, deployment steps, manual delivered | `PageSmokeTest` (seeds demo data, renders 32 screens × 6 roles) | — | ✅ |

## Additional automated checks
- Approved quantities can only be reduced (PM ≤ requested; Overall ≤ PM).
- Revising a rejected request creates version n+1, and old decisions stay in history.
- Only draft lines are editable; approval, ledger and voucher records are immutable.
- A second decision on the same step is refused.
- An adjustment cannot make stock negative and needs a reason. Opening balance is allowed once per item.
- Weighted average cost is correct. Document numbers are sequential and unique.
- Login, inactive-user lockout, forced password change, and the e-mailed password reset link.

## Manual UAT scripts (owner-observed)

**S1 — Happy path with two approvals**
1. staff1: New request → PRJ-001 → Cement 20 bags, Nails 5 kg → Submit. Status is *Awaiting PM*.
2. pm1: Approvals → open the request → reduce nails to 3 → Approve. Status is *Awaiting Overall Manager*.
3. overall: Approvals → Approve. Status is *Approved – ready to issue*. Item on-hand is **unchanged**.
4. store: Issues → Issue → Confirm. Voucher IV-… is created; on-hand drops by 20 and 3.
5. Print the voucher and check the signature lines. staff1 opens the voucher and clicks *Confirm materials received*.

**S2 — Partial issue (A7)**: create a request for 10 of an item whose stock you have adjusted to 6. After approval the issue screen caps the quantity at 6; issue 6, and the status becomes *Partially issued*. Record a receipt of 20; the issue screen now caps at 4. Issue 4, and the status becomes *Fully issued*.

**S3 — Rejection & revision**: pm2 rejects request 4 (demo) with a reason → staff2 clicks *Revise & resubmit* → it becomes v2 in Draft, and the old decision is still visible in history.

**S4 — Authorization spot-check**: as staff1, paste `/users`, `/receipts/create`, `/expenses`, and `/projects/2` into the address bar. Each one returns *403 Forbidden*.

**S5 — Reports**: Reports → each report → Export CSV opens in Excel. In *Project budget vs cost*, total = materials issued + cash expenses.

Sign-off: ____________________ (Owner)  Date: __________
