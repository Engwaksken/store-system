# Training Plan

Format: hands-on sessions on a **training copy** loaded with demo data (`php artisan migrate:fresh --seed` on a non-production database). Each person logs in with the demo account for their role (password `Demo12345`), then with their real account at the end to change their password. Manual: `docs/USER_MANUAL.md`. Practice scripts: `docs/UAT_TEST_CASES.md` §4.

| Session | Who | Length | Content | Practice |
|---|---|---|---|---|
| 1. Everyone | all users | 45 min | Logging in, password, the bell 🔔, dashboard, where your work waits; how a request flows (staff → PM → Overall → store → confirm receipt) | S1 together |
| 2. Site staff & PMs | staff, PMs | 60 min | Material requests with BOQ lines, revising after rejection, confirming receipt, expense claims with receipt photos; PMs: approving/reducing quantities, project *Budget, BOQ & milestones*, claims approval | S1, S3, S9, S8 |
| 3. Stores | storekeepers | 90 min | Issuing (store selector, partial issue, voucher PDF), receipts and opening balances (CSV), transfers (dispatch / confirm, shortfalls), returns, stock counts, adjustments; GRN against a PO | S2, S4, S5, S6, S7 (GRN part) |
| 4. Procurement & finance | procurement, accountant | 90 min | Suppliers, requisitions, quotes and selection reasons, POs and the approval threshold, supplier invoices and three-way match, exceptions, payments; expenses, claims payment, cost codes | S7, S9 |
| 5. Management | owner, overall manager | 60 min | Approvals across modules, PO threshold, stock count approval, invoice exceptions, budget alerts and variance, reports & exports, activity log | S7, S8, S11 |
| 6. Administration | owner/admin (+ IT contact) | 60 min | Users and roles, store assignments, settings and logo, imports (items, BOQ, opening balances), backups and restore drill, `cms:go-live-check`, `cms:reconcile`, `cms:verify-audit` | S12, DEPLOYMENT §5–6 |
| 7. Auditor | auditor | 30 min | Read-only navigation, exports, activity log export and hash column | S10, S11 |

## Attendance & competence record
| Name | Role | Sessions attended | Can perform own tasks unaided (✓) | Trainer | Date |
|---|---|---|---|---|---|
| | | | | | |

Refresher: 30 minutes per role two weeks after go-live, driven by questions raised in the warranty period.
