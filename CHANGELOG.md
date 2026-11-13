# Changelog

## Phase 5 – Reporting, alerts & security (13 Nov 2026)
- Exports: every report to Excel (.xlsx, built-in writer) and PDF (dompdf), plus print and CSV; PDF issue voucher, purchase order and GRN with company branding and signature blocks. Exports keep row-level scoping.
- Notifications: in-app bell for every hand-off (requests, transfers, counts, requisitions, POs, invoice exceptions, claims) with optional e-mail; daily reminders and escalation; budget variance alerts per project and cost code, once per level.
- New **Auditor** role: read-only access to every register, report and the audit log; all ~70 mutating routes refuse it (tested).
- Tamper-evident activity log (HMAC hash chain), `cms:verify-audit` scheduled daily, audit export to Excel/CSV.
- CSV imports with templates for catalogue items and BOQ lines (all-or-nothing validation).
- Security headers (CSP, frame/nosniff/referrer/permissions, HSTS), `no-store` on authenticated pages, per-user request throttle; decision endpoints authorize before validating.
- Owner dashboard: cross-module work queues, open POs, unpaid/overdue supplier invoices, stock value per store.
- Performance: approval lists use eager-loaded approvals; a test guards that dashboard/approval queries do not grow with pending items.
- Tests: `ReportingAndSecurityTest` (12), `AuditorAccessTest`. Full suite 106 tests on SQLite and MariaDB 10.4.

## Phase 4 – Project controls (13 Nov 2026)
- Cost codes (default set seeded), budgets per cost code, BOQ lines, milestones on a new *Budget, BOQ & milestones* page per project.
- Material request lines can be linked to a BOQ line of the same project; issues and returns roll up to BOQ lines and cost codes.
- Expense claims: submitted by project members, approved by the PM (or Overall Manager/Admin; never the claimant), paid by the accountant into exactly one expense.
- Commitments (approved POs not yet received + approved claims not yet paid) shown beside actuals; variance with warn (≥ 90 %) and over-budget flags on the dashboard, project page and reports.
- New reports: budget by cost code, consumption per BOQ line, open commitments; project budget report now includes committed cost.
- Tests: `ProjectControlsTest` (8). Full suite 93 tests on SQLite and MariaDB 10.4.

## Phase 3 – Procurement (13 Nov 2026)
- New role **Procurement Officer**; suppliers register.
- Purchase requisitions (store restock or project), approved by Owner/Admin or Overall Manager, never the requester.
- Supplier quotations per requisition, with a written reason when the chosen quote is not the lowest.
- Purchase orders: prices locked to the selected quote; approval never by the creator; above a configurable owner threshold only the Owner/Admin; optimistic locking; cancel before receipt, close on short delivery; printable PO.
- Goods received against the PO into its delivery store (no over-receipt, idempotent); stock valued at PO price.
- Supplier invoices with three-way match (PO price, received qty, invoiced qty); exceptions block payment until independently approved; duplicate supplier invoice numbers refused; private scan attachment.
- Supplier payments recorded (no bank transactions), with unpaid / partially paid / paid status.
- Tests: `ProcurementTest` (11). Full suite 85 tests on SQLite and MariaDB 10.4.

## Phase 2 – Multi-store stock control (13 Nov 2026)
- Stores: central store plus project site stores; storekeepers are assigned per store. All existing stock and history moved into store `MAIN` by the migration, and existing storekeepers are assigned to it.
- Receipts, opening balances (once per item per store), adjustments and issues now name a store. Site stores issue only to their own project.
- Transfers are two-step: dispatch (stock leaves the source, *in transit*) → receipt confirmed at the destination by a different person, with mandatory shortfall reasons. In-transit transfers can be cancelled.
- Returns to store against the issue voucher; the project is credited at the voucher cost, and project cost reports show net materials.
- Stock counts: frozen book quantities, variance reasons, approval by Owner/Admin or Overall Manager (never the counter) posts the adjustments.
- Duplicate-post protection (idempotency keys) on receipts, issues, transfers and returns.
- Reports: store filter on stock summary and movement ledger; issued-by-project report shows returns and net cost.
- Tests: `MultiStoreStockTest`, rewritten `StoreTransferRoutesTest`; full suite also passes on MariaDB 10.4.

## Phase 1 – MVP (12 Nov 2026)
See `docs/MVP_SCOPE.md` and `docs/BACKLOG.md` (B-01 … B-26).
