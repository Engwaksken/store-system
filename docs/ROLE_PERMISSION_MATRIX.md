# Role & Permission Matrix

Every permission is enforced on the server (policies/gates in `app/Policies`, `app/Providers/AppServiceProvider.php`). Hiding a menu item is cosmetic only, and a direct HTTP request without the permission returns **403**.

Roles (`users.role`): `admin` (Owner/Admin), `overall_manager`, `project_manager`, `storekeeper`, `staff`, `accountant`, `procurement` (Procurement Officer, Phase 3), `auditor` (read-only, Phase 5).

"Assigned" means the user is the project's `manager_id` or appears in `project_user` (ticked on the user record or on the
project's team list). For a **storekeeper**, assigned projects also include the project of any site store they are
assigned to. Procurement Officers (company-wide buying role) see every project.

| Capability | Admin | Overall Mgr | Project Mgr | Storekeeper | Staff | Accountant |
|---|---|---|---|---|---|---|
| Manage users / settings | ✅ | — | — | — | — | — |
| View activity log | ✅ | ✅ | — | — | — | — |
| Create / edit projects, assign staff | ✅ | ✅ | — | — | — | — |
| View projects | all | all | managed/assigned | **assigned** (+ site store's project) | assigned | all |
| Add / edit items, import items (CSV) | ✅ | view | view | ✅ | view | view |
| Categories, units, delete items (Catalog setup) | ✅ | — | — | — | — | — |
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
| PM-step approve/reject | ✅ any request, never own (recorded as substitute) | — | **own projects only**, never own request | — | — | — |
| Overall-step approve/reject | ✅ any request, never own | ✅ (not requester, not PM-step approver) | — | — | — | — |
| Issue materials | — | — | — | ✅ **assigned projects only**, from assigned store (only OVERALL_APPROVED / PARTIALLY_ISSUED; site store only to its project) | — | — |
| Acknowledge receipt | — | — | requester | records named recipient | requester | — |
| View requests | all | all | managed projects + own | approved/issuable + issued, assigned projects | own | all |
| View issue vouchers | all | all | as requests | from assigned stores or for assigned projects | as requests | all |
| Record / edit project expenses | ✅ | — | — | — | — | ✅ |
| View expenses | all | all | managed projects | — | — | all |
| Reports & CSV | all | all | managed projects | stock reports | — | all |

¹ Admin may request on any project. The Owner/Admin may approve any request at either step, but never their own: an admin request goes PM → Overall in the normal way.

Procurement Officers may also add and edit catalogue items (not categories, units or deletions).

## Procurement (Phase 3)
Full policy: `docs/PROCUREMENT.md`.

| Capability | Admin | Overall Mgr | Project Mgr | Storekeeper | Staff | Accountant | Procurement |
|---|---|---|---|---|---|---|---|
| View suppliers | ✅ | ✅ | — | ✅ | — | ✅ | ✅ |
| Create / edit suppliers | ✅ | — | — | — | — | — | ✅ |
| Raise purchase requisition | ✅ | — | own projects | ✅ delivering to assigned stores | — | — | ✅ any project |
| View requisitions | all | all | own + managed projects | own + delivering to assigned stores (incl. automatic restocks) | — | all | all |
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

## Deleting records (list pages, multiple selection)
Only master data that was never used, drafts and personal items can be deleted; a record in use is skipped and listed
(restrict foreign keys refuse it even if a check were missed). Posted documents and the audit log are never deleted.

| Records | Who | Deletable when |
|---|---|---|
| Projects | Admin, Overall Manager | no requests, vouchers, expenses, claims, purchasing, budgets/BOQ/milestones or site store |
| Stores | Admin | not the main store; no stock history and no stock |
| Suppliers | Admin, Procurement | no quotes, orders, invoices or payments |
| Cost codes | Admin, Accountant | not used by budgets, expenses, claims or orders |
| Catalogue items, categories, units | Admin | see Catalog setup |
| Purchase requisitions | requester or Admin | draft, rejected or cancelled, no quotes or orders |
| Material requests, expense claims | as before (drafts / own) | existing rules |
| Stock counts | the counter or Admin | draft |
| Feedback | Admin | always |
| Notifications | the recipient | always |
| Users | — | never; Admin bulk-deactivates (not self, not the last active Admin) |

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
