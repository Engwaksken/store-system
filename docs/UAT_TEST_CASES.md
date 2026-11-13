# UAT Test Cases & Traceability

Automated coverage: `php artisan test` — **110 feature tests (1,420 assertions)**, all passing on SQLite (in-memory) and on MariaDB 10.4 (13 Nov 2026). Evidence of MySQL-only runs (parallel race, reconciliation, backup/restore) is in `docs/UAT_EVIDENCE.md`.

Manual UAT uses the demo data (`php artisan migrate --seed` on a **non-production** copy). Every demo account uses the password `Demo12345`.

| Demo account | Role | Notes |
|---|---|---|
| owner@demo.test | Owner / Admin | |
| overall@demo.test | Overall Manager | final approvals, PR/PO/count/claim approvals |
| pm1@demo.test / pm2@demo.test | Project Manager | PRJ-001 (has budgets, BOQ, milestones) / PRJ-002 |
| store@demo.test | Storekeeper | MAIN store and Kira site store |
| sitestore@demo.test | Storekeeper | Kira site store only (receives transfers) |
| buyer@demo.test | Procurement Officer | |
| accounts@demo.test | Accountant | |
| auditor@demo.test | Auditor | read-only |
| staff1@demo.test / staff2@demo.test | Staff | PRJ-001 / PRJ-002 |

## 1. Required test scenarios (brief) → evidence
| # | Scenario from the brief | Automated test(s) | Result |
|---|---|---|---|
| R1 | Unassigned PM denied | `ApprovalWorkflowTest::test_wrong_pm_cannot_approve`, `AccessControlTest::test_unauthorized_direct_requests_are_rejected_regardless_of_menus`, `ProcurementTest::test_pm_raises_pr_only_for_own_project`, `ProjectControlsTest::test_controls_page_and_edit_rights` | ✅ |
| R2 | Self-approval denied | `test_pm_as_requester_cannot_self_approve_admin_substitutes_and_overall_stays_independent`, `test_requester_cannot_approve_own_pr_and_rejection_needs_reason`, `test_po_approval_policy_creator_threshold_and_stale_version`, `test_pm_claim_goes_to_overall_and_rejection_needs_reason`, `test_count_variance_posts_only_after_independent_approval_with_reason` | ✅ |
| R3 | Final manager cannot approve before PM | `test_overall_cannot_decide_before_pm` | ✅ |
| R4 | Storekeeper cannot issue before final approval | `test_storekeeper_cannot_issue_until_both_stages_complete`, `test_approval_alone_does_not_decrement_stock` | ✅ |
| R5 | Rejection blocks issue | `test_pm_reject_blocks_overall_approval_and_issue`, `test_overall_reject_blocks_issue` | ✅ |
| R6 | Simultaneous requests cannot make stock negative | `test_final_units_cannot_be_issued_twice_to_competing_requests` + **parallel** `cms:concurrency-test` (8 processes, MariaDB, multi-store code: 6/6 checks) | ✅ |
| R7 | Partial issue cannot exceed approved quantity | `test_approved_10_with_6_available_scenario`, `test_failed_multi_line_issue_rolls_back_everything` | ✅ |
| R8 | Rejected request revisions preserve audit history | `test_revise_after_rejection_creates_new_version_and_requires_fresh_approvals`, `test_approval_records_are_immutable`, audit chain tests | ✅ |
| R9 | Project staff cannot see other projects | `test_project_visibility_is_role_scoped`, `test_requests_are_visible_only_to_permitted_users`, `test_staff_not_assigned_to_project_cannot_request_for_it` | ✅ |
| R10 | Return and transfer reconcile | `test_transfer_and_return_reconcile_across_stores`, `test_short_receipt_needs_reason_and_ledger_reconciles`, `test_cancel_returns_stock_to_source`, `GoLiveTest` (`cms:reconcile` on the full demo set) | ✅ |
| R11 | Duplicate issue request cannot double-post | `test_duplicate_issue_post_with_same_key_does_not_double_post`, `test_duplicate_receipt_post_with_same_key_does_not_double_post`, `test_duplicate_grn_post_does_not_double_receive`, `test_second_decision_on_same_step_is_refused` | ✅ |
| R12 | Stock count adjustment requires authorized reason | `test_count_variance_posts_only_after_independent_approval_with_reason`, `test_rejected_count_posts_nothing_and_requires_reason`, `test_adjustment_cannot_make_stock_negative_and_needs_reason` | ✅ |
| R13 | Project expense appears exactly once | `test_claim_is_approved_independently_and_paid_into_expenses_exactly_once` (DB unique key too), `test_store_purchases_are_not_counted_as_project_cost`, `test_return_credits_project_once_and_cannot_exceed_what_is_out` | ✅ |
| R14 | Exports obey row-level permissions | `test_exports_obey_row_level_permissions` (CSV, Excel, PDF), `test_finance_reports_render_and_are_scoped` | ✅ |

## 2. Definition of done → evidence
| Item | Evidence | Status |
|---|---|---|
| All acceptance tests pass | 110 tests, SQLite + MariaDB | ✅ |
| No critical/high security defects | `docs/SECURITY.md`; `AuditorAccessTest` (all mutating routes refuse a read-only user), `AccessControlTest`, headers test | ✅ internal review · ☐ owner's independent review if required |
| Stock ledger reconciles with balances | `cms:reconcile` (daily schedule), `GoLiveTest`, run on the raced MariaDB DB | ✅ |
| Approval audit is complete | immutable approval rows + hash-chained activity log, `cms:verify-audit` | ✅ |
| Owner signs UAT | §5 below | ☐ owner |
| Production backup/restore tested | local drill passed (`UAT_EVIDENCE.md`) | ☐ repeat on the production host |
| Repository and credentials handed over securely | `docs/HANDOVER.md` | ☐ at handover |

## 3. Earlier acceptance criteria (Phase 1) — still passing
A1–A13 from the MVP remain covered by `ApprovalWorkflowTest`, `IssuanceAndStockTest`, `AccessControlTest`, `PolishFeaturesTest`, `BackupCommandTest`, `PageSmokeTest` (every screen × 9 roles with demo data).

## 4. Manual UAT scripts (owner-observed)
**S1 — Two approvals and issue.** staff1: New request → PRJ-001 → Cement 20 bags (BOQ line 3.1), Nails 5 kg → Submit. pm1 (🔔 shows the alert): reduce nails to 3 → Approve. overall: Approve. On-hand is unchanged. store: Issues → Issue (store MAIN) → Confirm → voucher IV-…; download the PDF. staff1 confirms receipt.

**S2 — Partial issue.** Request 10 of an item with 6 on hand in the chosen store. Issue is capped at 6 → *Partially issued*. Receive 20 → issue the remaining 4 → *Fully issued*; nothing above 10 is possible.

**S3 — Rejection & revision.** pm2 rejects demo request 4 with a reason → staff2 *Revise & resubmit* → v2 Draft; the v1 decision stays in history.

**S4 — Transfer with two-person confirmation.** store: Transfers → New → MAIN → Kira site store → 10 bags → Dispatch. Log in as store again and open it: no *Confirm receipt* form. sitestore: confirm 9 received, reason "1 bag torn". MAIN fell by 10, Kira rose by 9; the line shows a shortfall of 1 with the reason.

**S5 — Return.** store: open the demo voucher → *Return to store* → return 2 bags → PRJ-001 cost drops by the voucher value (project page).

**S6 — Stock count.** sitestore: the demo count awaits approval. Log in as overall → Stock counts → approve → an adjustment ADJ-… is posted with the reason.

**S7 — Procure to pay.** buyer: open demo PR → quotes already recorded → raise the PO from the second PR or open the pending demo PO. overall approves (buyer cannot). store: Receive goods (GRN) part of it. accounts: record the supplier invoice at a *different* price → *Match exception* → overall approves the exception → accounts records payment. PO PDF downloads with signature blocks.

**S8 — Budget control.** pm1: PRJ-001 → *Budget, BOQ & milestones*: budget, actual, committed and variance per cost code; set the concrete budget below actual+committed → red. Run `php artisan cms:budget-alerts` → management and pm1 receive an alert once.

**S9 — Expense claim.** staff1: Expense claims → New → 50,000 transport with a photo. pm1 approves; accounts pays → the claim appears once in PRJ-001 expenses.

**S10 — Authorization spot-check.** As staff1 paste `/users`, `/receipts/create`, `/purchase-orders`, `/projects/2/controls` → 403. As auditor@ everything opens read-only and no action buttons work.

**S11 — Reports & exports.** Reports → each report → Excel, PDF and CSV. As pm2, exports contain PRJ-002 only.

**S12 — Integrity commands.** `php artisan cms:reconcile` → *All books reconcile*; `php artisan cms:verify-audit` → *Audit chain intact*; `php artisan cms:go-live-check` on the production server → no FAIL lines.

## 5. Sign-off
| Area | Accepted (✓/✗) | Comments |
|---|---|---|
| Material requests, approvals, issue | | |
| Stores, transfers, returns, counts | | |
| Procurement (PR, PO, GRN, invoices, payments) | | |
| Project controls (budgets, BOQ, claims, commitments) | | |
| Reports, exports, notifications | | |
| Security & audit | | |

Owner: ____________________  Signature: ____________  Date: __________
