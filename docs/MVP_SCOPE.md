# MVP Scope — Construction Store & Project CMS (Phase 1)

Baseline: the revised PM implementation brief and owner proposal (7 Nov 2026).
This document is the **frozen Phase 1 scope**. Any change requires a written change note approved by the owner.

## 1. Repository audit (12 Nov 2026)

| Item | Finding |
|---|---|
| `D:\projects\store-system` | Empty at start: no prior code, schema or tests to reuse. |
| `D:\projects\Company Store` | Design artefacts only: clickable HTML prototype, 7 SVG screen boards, draw.io flowcharts, proposals. No application code. |
| Decision | New Laravel 12 application. The visual language (navy `#12243E`, gold `#D4A33A`, teal `#0F766E`, bg `#F4F7FB`, 245px sidebar) and screen list come from the design pack. |

No working features existed, so nothing could be removed.

## 2. Technical baseline (chosen to keep hosting simple and inexpensive)

- Laravel 12, PHP ≥ 8.2, MySQL 8 / MariaDB 10.4+ in production. SQLite is used only for the automated test suite.
- Server-rendered Blade with one hand-written CSS file. No Node build step, so it deploys to ordinary shared hosting.
- Roles live in a `role` column on `users`, with authorization in Laravel Policies/Gates. No paid or third-party permission package.
- Authentication is built in: login, logout, password reset by e-mail token, and forced password change on first login. There is **no self-registration**; the admin creates users.
- File uploads (expense proofs) go to the local `storage/app/private` disk and are served only through an authorized controller.

## 3. In scope (Phase 1)

| # | Module | Delivered capability |
|---|---|---|
| M1 | Access & roles | Six roles: Owner/Admin, Overall Manager, Project Manager, Storekeeper, Staff, Accountant. Login, password reset, user management (admin), activity log. |
| M2 | Projects | CRUD, unique code, client/location, dates, status, budget, `manager_id`, staff assignment, role-scoped visibility. |
| M3 | One-store inventory | Categories, units, items (SKU, min level, weighted average cost), opening balances, stock receipts, balance per item, append-only `stock_movements` ledger, low-stock indicator. |
| M4 | Requisitions | Header + lines, project, purpose, needed-on date, PM step, independent Overall step, approve/reject with comments and timestamps, immutable decision log, revision after rejection. |
| M5 | Controlled issuance | Storekeeper issues only after both approvals; partial issue; race-safe transaction with row locks; numbered issue voucher (print); recipient acknowledgment. |
| M6 | Project expenses | Category, date, amount, payment mode, reference, optional proof. Budget summary keeps **material consumption** and **cash expenses** in separate columns. |
| M7 | Reporting & handover | Owner dashboard, stock summary, stock movements, pending approvals, issued-by-project, expenses-by-project. Print view and CSV export. Deployment guide, backup/restore guide, quick user manual. |

## 4. Explicitly deferred to Phase 2. Not scaffolded, not promised.

Multiple stores and inter-store transfers; purchasing/RFQ/PO workflows; supplier balances and supplier portal; advanced BOQ; payroll; equipment; native mobile app; offline sync; barcode/RFID; SMS/WhatsApp; external accounting integration; multi-company tenancy; elaborate analytics; bulk legacy-data migration (go-live data is opening balances entered or CSV-pasted by the admin only).

## 5. Design defaults (documented so they don't block; owner may override at sign-off)

| # | Decision point | Default adopted |
|---|---|---|
| D1 | Who is the "overall approver"? | Any active user with role **Overall Manager**. The owner names them at sign-off. Owner/Admin does **not** approve by default, so independence is preserved. |
| D2 | Requester is also the project's PM | The PM step routes to **Owner/Admin** as a substitute reviewer, because the PM cannot approve their own request. The substitution is recorded on the approval row. |
| D3 | Independence rule | The Overall approver must differ from the requester **and** from the PM-step approver. The PM-step approver must differ from the requester. These checks run on the server. |
| D4 | Rejection | Rejection at either stage ends the current version. The requester may **Revise**: the request returns to DRAFT as version n+1, approved quantities reset, and old decisions stay in history. |
| D5 | Editing after approval | Lines can be edited only in DRAFT. Any change to an approved request has to go through Revise, which invalidates prior approvals. |
| D6 | Approved quantity | The PM may reduce quantities (never increase above requested). The Overall Manager may reduce again (never above the PM-approved quantity). A line approved at 0 is effectively declined. |
| D7 | Costing method | Weighted average cost, updated on each receipt. Each issue line snapshots the current average cost. |
| D8 | Who acknowledges receipt | The requester, or the storekeeper recording a named recipient with a signature line on the printed voucher. |
| D9 | Store purchases vs project expenses | Materials bought **into the store** are recorded only as stock receipts and charged to projects only when issued. Project expenses are cash items paid directly for the project (labour, transport, direct-to-site purchases). Reports show them in separate columns and never add store receipts to project cost. |
| D10 | Corrections | Issues are never edited or deleted. A correction is a **stock adjustment** (admin/storekeeper, mandatory reason), posted as its own ledger movement. |
| D11 | Currency precision | Money is `DECIMAL(15,2)`, quantities `DECIMAL(14,3)`, unit cost `DECIMAL(15,4)`. UGX is displayed without decimals. |

