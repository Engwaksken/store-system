# Prioritised Backlog & Status (13 Nov 2026)

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
| B-11 | Race-safe partial issuance (transaction, row locks, re-validation), voucher, acknowledgment | ✅ | Sequential tests plus parallel MariaDB run (`docs/UAT_EVIDENCE.md`). Full suite also passes on MariaDB |
| B-12 | Expenses with proof upload (private disk), scoped access | ✅ | `test_expense_access_is_permission_controlled_and_scoped` |
| B-13 | Cost separation: materials issued vs cash expenses, no double count | ✅ | `test_store_purchases_are_not_counted_as_project_cost` |

## P1: reporting and handover
| ID | Item | Status | Evidence |
|---|---|---|---|
| B-14 | Owner dashboard (queues, low stock, budget vs cost) | ✅ | smoke test |
| B-15 | Six reports with print view and CSV (formula-injection safe) | ✅ | smoke test, CSV assertion |
| B-16 | Activity log | ✅ | |
| B-17 | Demo seed for UAT and training | ✅ | `DemoSeeder`, `PageSmokeTest` |
| B-18 | Daily backup command plus schedule | ✅ | mysqldump backup verified on MariaDB (`docs/UAT_EVIDENCE.md`). Confirm the host allows mysqldump |
| B-19 | Restore drill | 🟡 | Passed locally (`docs/UAT_EVIDENCE.md`); repeat on the production host |
| B-20 | Production deploy to client domain | ⬜ | Needs hosting account and domain from owner |
| B-21 | Training session plus quick manual | 🟡 | `docs/USER_MANUAL.md` written; session not held |
| B-22 | Owner UAT sign-off | ⬜ | `docs/UAT_TEST_CASES.md` |

## P2: polish within agreed scope
| ID | Item | Status |
|---|---|---|
| B-23 | Logo on voucher header | ✅ (needs `php artisan storage:link` on server) |
| B-24 | "Add line" button on request and receipt forms | ✅ `test_forms_offer_add_line_button` |
| B-25 | E-mail alerts along the chain: PM on submit, Overall after PM, storekeeper and requester after final approval, requester on rejection and on issue. Sent after commit; mail failure never blocks a decision. | ✅ `PolishFeaturesTest` (4 tests) |
| B-26 | Opening-balance CSV template download and all-or-nothing import | ✅ `PolishFeaturesTest` (3 tests) |

## Phase 2: multi-store stock control
| ID | Item | Status | Evidence |
|---|---|---|---|
| B-27 | Stores (central / project site), storekeeper assignment, per-store balances; existing data migrated to MAIN | ✅ | `StoreTransferRoutesTest`, `MultiStoreStockTest` |
| B-28 | Receipts, opening balances, adjustments and issues per store; server-side store scoping | ✅ | `test_storekeeper_cannot_receive_into_or_adjust_an_unassigned_store`, `test_site_store_cannot_issue_to_another_project...` |
| B-29 | Two-step transfers: dispatch → receive by a different person; shortfall reason; cancel while in transit | ✅ | `test_dispatch_then_receive_by_a_different_storekeeper`, `test_short_receipt_needs_reason...`, `test_cancel_returns_stock_to_source` |
| B-30 | Returns to store against the voucher; project credited at voucher cost; cannot exceed what is out | ✅ | `test_return_credits_project_once_and_cannot_exceed_what_is_out` |
| B-31 | Stock counts: frozen book qty, reasons for variances, independent approval posts adjustments | ✅ | `test_count_variance_posts_only_after_independent_approval_with_reason` |
| B-32 | Idempotency keys on receipts, issues, transfers, returns | ✅ | `test_duplicate_issue_post_with_same_key_does_not_double_post` |
| B-33 | Ledger reconciles per store × item after transfers, issues, returns, adjustments | ✅ | `test_transfer_and_return_reconcile_across_stores` |
| B-34 | Upgrade drill: run the Phase 2 migration on a copy of the live Phase 1 database | ⬜ | Fresh migration verified on MariaDB 10.4 (full suite); repeat on a production copy before go-live |

## Phase 3: procurement
| ID | Item | Status | Evidence |
|---|---|---|---|
| B-35 | Suppliers, Procurement Officer role | ✅ | `ProcurementTest::test_procurement_pages_and_role_visibility` |
| B-36 | Purchase requisitions with independent approval and mandatory rejection reason | ✅ | `test_requester_cannot_approve_own_pr_and_rejection_needs_reason`, `test_pm_raises_pr_only_for_own_project` |
| B-37 | Supplier quotes; justification when not the lowest; PO must match selected quote | ✅ | `test_choosing_a_dearer_quote_needs_a_reason_and_po_must_match_quote` |
| B-38 | PO approval policy: not the creator, owner threshold, optimistic lock | ✅ | `test_po_approval_policy_creator_threshold_and_stale_version` |
| B-39 | GRN against PO into the delivery store; no over-receipt; idempotent | ✅ | `test_grn_cannot_exceed_outstanding...`, `test_duplicate_grn_post_does_not_double_receive` |
| B-40 | Supplier invoices with three-way match; exceptions need independent approval; duplicate invoice numbers refused | ✅ | `test_invoice_mismatch_blocks_payment_until_independent_approval`, `test_same_supplier_invoice_cannot_be_entered_twice` |
| B-41 | Payments (records only), status unpaid / partial / paid, no overpayment | ✅ | `test_full_procure_to_pay_cycle` |
| B-42 | PO cancel (before receipt) and close (short delivery) | ✅ | `test_cancel_reopens_requisition_but_not_after_receipt` |
| B-43 | Printable PO with company branding and signatory blocks | ✅ | `pos/show` print view (PDF export: Phase 5) |
| B-44 | Owner to confirm the PO owner-approval threshold (default 20,000,000 UGX) | 🟡 | Settings screen |

## Phase 4: project controls
| ID | Item | Status | Evidence |
|---|---|---|---|
| B-45 | Cost codes (seeded defaults; admin/accountant manage) | ✅ | `test_cost_codes_are_managed_by_admin_and_accountant_only` |
| B-46 | Budgets per cost code, BOQ lines, milestones (PM of the project / management) | ✅ | `test_controls_page_and_edit_rights` |
| B-47 | Request lines linked to BOQ lines (same project only); consumption per BOQ line | ✅ | `test_boq_line_must_belong_to_the_request_project`, `test_budget_vs_committed_vs_actual_by_cost_code` |
| B-48 | Expense claims: independent approval, paid once into expenses | ✅ | `test_claim_is_approved_independently_and_paid_into_expenses_exactly_once` |
| B-49 | Commitments (open POs + approved claims) vs actuals, variance and warn/over flags on dashboard, project page and reports | ✅ | same, `test_finance_reports_render_and_are_scoped` |
| B-50 | Variance e-mail / in-app alerts | ⬜ | Phase 5 (notifications) |

## Owner decisions still open (defaults in effect, see MVP_SCOPE §5)
1. Name the Overall Manager and the primary Storekeeper (D1).
2. Confirm the substitute PM-step reviewer when a PM requests on their own project (default: Owner/Admin, D2).
3. Provide the hosting account and domain, project list, and opening stock count.

## Deferred: Phase 2, explicitly excluded
supplier portal · payroll · equipment · native mobile app · offline sync · barcode/RFID · SMS/WhatsApp · accounting integration · multi-company · advanced analytics · bulk legacy migration.
