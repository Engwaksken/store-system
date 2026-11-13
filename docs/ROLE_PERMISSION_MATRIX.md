# Role & Permission Matrix

Every permission is enforced on the server (policies/gates in `app/Policies`, `app/Providers/AppServiceProvider.php`). Hiding a menu item is cosmetic only, and a direct HTTP request without the permission returns **403**.

Roles (`users.role`): `admin` (Owner/Admin), `overall_manager`, `project_manager`, `storekeeper`, `staff`, `accountant`, `procurement` (Procurement Officer, Phase 3), `auditor` (read-only, Phase 5).

"Assigned" means the user is the project's `manager_id` or appears in `project_user`.

| Capability | Admin | Overall Mgr | Project Mgr | Storekeeper | Staff | Accountant |
|---|---|---|---|---|---|---|
| Manage users / settings | ✅ | — | — | — | — | — |
| View activity log | ✅ | ✅ | — | — | — | — |
| Create / edit projects, assign staff | ✅ | ✅ | — | — | — | — |
| View projects | all | all | managed/assigned | all (name/code only, for issuing) | assigned | all |
| Item catalog, categories, units | ✅ | view | view | ✅ | view | view |
| Opening balances, stock receipts, adjustments | ✅ any store | — | — | ✅ assigned stores | — | — |
| View stores & per-store balances | ✅ | ✅ | — | ✅ | — | ✅ |
| Create store / edit store | ✅ | — | — | ✅ (creator is assigned; edit assigned stores) | — | — |
| Assign storekeepers to stores | ✅ | — | — | — | — | — |
| Dispatch transfer | ✅ | — | — | from assigned store | — | — |
| Confirm transfer receipt | ✅ | — | — | into assigned store, **not the dispatcher** | — | — |
| Cancel in-transit transfer | ✅ | — | — | dispatcher only | — | — |
| Record return to store (against voucher) | ✅ | — | — | into assigned store | — | — |
| Start / enter / submit stock count | ✅ | — | — | assigned store | — | — |
| Approve / reject stock count | ✅ (not the counter) | ✅ (not the counter) | — | — | — | — |
| View transfers, returns, counts | all | all | — | own stores (transfers) / all | — | all |
| View stock balances & ledger | ✅ | ✅ | ✅ | ✅ | ✅ (balance only) | ✅ |
| Create / submit material request | ✅¹ | — | assigned projects | — | assigned projects | — |
| PM-step approve/reject | substitute only (D2) | — | **own projects only**, never own request | — | — | — |
| Overall-step approve/reject | — | ✅ (not requester, not PM-step approver) | — | — | — | — |
| Issue materials | — | — | — | ✅ from assigned store (only OVERALL_APPROVED / PARTIALLY_ISSUED; site store only to its project) | — | — |
| Acknowledge receipt | — | — | requester | records named recipient | requester | — |
| View requests | all | all | managed projects + own | approved/issuable + issued | own | all |
| Record / edit project expenses | ✅ | — | — | — | — | ✅ |
| View expenses | all | all | managed projects | — | — | all |
| Reports & CSV | all | all | managed projects | stock reports | — | all |

¹ Admin may request on any project. Because admin is never an approver except as the D2 substitute, an admin request goes PM → Overall in the normal way.

## Procurement (Phase 3)
Full policy: `docs/PROCUREMENT.md`.

| Capability | Admin | Overall Mgr | Project Mgr | Storekeeper | Staff | Accountant | Procurement |
|---|---|---|---|---|---|---|---|
| View suppliers | ✅ | ✅ | — | ✅ | — | ✅ | ✅ |
| Create / edit suppliers | ✅ | — | — | — | — | — | ✅ |
| Raise purchase requisition | ✅ | — | own projects | ✅ | — | — | ✅ |
| View requisitions | all | all | own + managed projects | own | — | all | all |
| Approve / reject requisition | ✅ (not own) | ✅ (not own) | — | — | — | — | — |
| Record quotes, select supplier, raise / cancel / close PO | ✅ | — | — | — | — | — | ✅ |
| Approve / reject PO | ✅ (not own PO; required above threshold) | ✅ up to threshold (not own PO) | — | — | — | — | — |
| View purchase orders | all | all | managed projects | delivery store assigned | — | all | all |
| Receive goods against PO (GRN) | ✅ | — | — | assigned delivery store | — | — | — |
| Record supplier invoice, record payment | ✅ | — | — | — | — | ✅ | — |
| View supplier invoices | ✅ | ✅ | — | — | — | ✅ | ✅ |
| Approve invoice match exception | ✅ (not recorder) | ✅ (not recorder) | — | — | — | — | — |

## Project controls & claims (Phase 4)
| Capability | Admin | Overall Mgr | Project Mgr | Storekeeper | Staff | Accountant | Procurement |
|---|---|---|---|---|---|---|---|
| View cost codes | ✅ | ✅ | ✅ | — | — | ✅ | ✅ |
| Manage cost codes | ✅ | — | — | — | — | ✅ | — |
| View budget / BOQ / milestones page | ✅ | ✅ | own projects | — | — | ✅ | — |
| Edit cost-code budgets, BOQ, milestones | ✅ | ✅ | own projects | — | — | — | — |
| Submit expense claim | ✅ | ✅ | assigned projects | — | assigned projects | ✅ | — |
| Approve / reject claim | ✅ (not own) | ✅ (not own) | own projects, not own claim | — | — | — | — |
| Pay claim (creates the expense) | ✅ | — | — | — | — | ✅ | — |
| Cost-code, BOQ and commitment reports | all | all | managed projects | — | — | all | — |

## Auditor (Phase 5)
Read-only: dashboard, projects (all), budget/BOQ pages (no edit), requests, issues/vouchers, stock, stores, transfers, returns, counts, receipts, requisitions, POs, suppliers, supplier invoices, claims, expenses, cost codes, every report and export (CSV / Excel / PDF), and the activity log with export. **No** create, edit, approve, post or pay action anywhere — `AuditorAccessTest` calls every mutating route and expects 403.

## Separation-of-duty rules (server-checked)
1. PM-step approver ≠ requester.
2. Overall approver ≠ requester **and** ≠ PM-step approver of the same version.
3. The storekeeper issues only. They cannot approve, and approving users cannot issue.
4. Inactive users (`is_active = false`) cannot log in.
5. A transfer is received by a different person from the one who dispatched it.
6. A stock count is approved by Admin/Overall Manager, never by the person who counted.
7. Store scoping is re-checked inside every stock service (`Store::assertOperable`), not only on routes.
8. A purchase requisition is never approved by its requester; a PO is never approved by its creator; POs above the owner threshold need the Owner/Admin.
9. A match exception on a supplier invoice is approved by someone other than the person who recorded the invoice, and blocks payment until then.
10. An expense claim is never approved by the claimant; a PM's own claim goes to the Overall Manager or Owner/Admin. A claim is paid once (unique `expenses.expense_claim_id`).
