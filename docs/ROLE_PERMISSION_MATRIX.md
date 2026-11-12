# Role & Permission Matrix

Every permission is enforced on the server (policies/gates in `app/Policies`, `app/Providers/AppServiceProvider.php`). Hiding a menu item is cosmetic only, and a direct HTTP request without the permission returns **403**.

Roles (`users.role`): `admin` (Owner/Admin), `overall_manager`, `project_manager`, `storekeeper`, `staff`, `accountant`.

"Assigned" means the user is the project's `manager_id` or appears in `project_user`.

| Capability | Admin | Overall Mgr | Project Mgr | Storekeeper | Staff | Accountant |
|---|---|---|---|---|---|---|
| Manage users / settings | ✅ | — | — | — | — | — |
| View activity log | ✅ | ✅ | — | — | — | — |
| Create / edit projects, assign staff | ✅ | ✅ | — | — | — | — |
| View projects | all | all | managed/assigned | all (name/code only, for issuing) | assigned | all |
| Item catalog, categories, units | ✅ | view | view | ✅ | view | view |
| Opening balances, stock receipts, adjustments | ✅ | — | — | ✅ | — | — |
| View stock balances & ledger | ✅ | ✅ | ✅ | ✅ | ✅ (balance only) | ✅ |
| Create / submit material request | ✅¹ | — | assigned projects | — | assigned projects | — |
| PM-step approve/reject | substitute only (D2) | — | **own projects only**, never own request | — | — | — |
| Overall-step approve/reject | — | ✅ (not requester, not PM-step approver) | — | — | — | — |
| Issue materials | — | — | — | ✅ (only OVERALL_APPROVED / PARTIALLY_ISSUED) | — | — |
| Acknowledge receipt | — | — | requester | records named recipient | requester | — |
| View requests | all | all | managed projects + own | approved/issuable + issued | own | all |
| Record / edit project expenses | ✅ | — | — | — | — | ✅ |
| View expenses | all | all | managed projects | — | — | all |
| Reports & CSV | all | all | managed projects | stock reports | — | all |

¹ Admin may request on any project. Because admin is never an approver except as the D2 substitute, an admin request goes PM → Overall in the normal way.

## Separation-of-duty rules (server-checked)
1. PM-step approver ≠ requester.
2. Overall approver ≠ requester **and** ≠ PM-step approver of the same version.
3. The storekeeper issues only. They cannot approve, and approving users cannot issue.
4. Inactive users (`is_active = false`) cannot log in.
