# Changelog

## Feedback round 2: restock tracking, issue statistics, profile photo, biometric unlock (19 Nov 2026)
- **Requesters track restocking**: the request page (web and mobile) shows each restock requisition with a plain-language
  step (waiting for approval, approved, ordered, delivered). Stock-shortage wording is now shown only to the
  storekeeper, Overall Manager and Owner/Admin. Requesters are notified when a restock is raised and decided.
- **Issue statistics** on the web and mobile dashboards: issue vouchers this month, items issued this month, vouchers
  not acknowledged (scoped to what each user may see).
- **Profile photo** (mobile, Edit profile): take or choose a photo, or remove it; shown on More and Profile.
  Migration `users.avatar_path`, `POST/DELETE /me/avatar`.
- **Biometric unlock** (mobile, Edit profile): fingerprint / face / phone PIN when the app opens and after 30 seconds
  in the background; turned off on sign-out. Android uses FlutterFragmentActivity and AppCompat launch themes.
- Mobile: About screen removed.
- Restock steps are skipped (logged) on a server where the restock migration has not run, instead of failing.
- Tests: `RestockTrackingAndProfileTest` (3), Flutter `profile_security_test` (4).

## User feedback round: restocking, catalogue fixes, stock prices, store operations on mobile (18 Nov 2026)
- **Short issues go to restocking**: the storekeeper issues what is in stock; the balance stays outstanding and a restock
  purchase requisition (quantity = larger of the balance not in stock and the minimum stock level) is submitted to the
  Overall Manager / Owner automatically. "Request restock only" when nothing can be issued. Linked both ways (request
  page and requisition page). New `RestockService`, migration `purchase_requisitions.material_request_id`.
- **Stock prices only for Owner/Admin and Overall Manager** (`view-stock-value`): item cost, stock value, voucher,
  receipt, return and ledger unit costs are hidden from other roles (web, PDFs and API). Procurement and finance
  documents (POs, invoices, claims) are unchanged.
- **Catalog setup**: deleting a category or unit that items still use now asks which one to move the items to, instead
  of a disabled button.
- **Stock & items**: tick rows and delete in bulk (items with stock history or used on documents are skipped and listed);
  quantities refresh automatically every minute (paused while rows are ticked, a dialog is open or you are typing).
- **Forms in dialogs**: clicking outside no longer closes them (Cancel, X or Esc still do).
- **Stock movement ledger** 500 fixed: lighter query, null-safe rows, the latest 1,000 rows on screen (5,000 in
  Excel/CSV, 500 in PDF) with a note when cut.
- **New material request**: the item list is split into "Available in store" and "Out of stock".
- **Mobile API + Flutter**: Store menu with issuing (queue, issue form with shortfall/restock, vouchers, acknowledge),
  returns, stock receipts and adjustments, transfers (dispatch, receive, cancel) and purchasing (requisitions incl.
  restock, purchase orders, approve/reject). Item picker filters Available / Out of stock. Notifications open vouchers,
  transfers, requisitions and orders. Fake backend supports all of it.
- Tests: `RestockAndCatalogFeedbackTest` (11), `MobileStoreOpsApiTest` (6), Flutter `store_ops_test` (7).

## Installable web app (PWA) for Android and iPhone (18 Nov 2026)
- The web app can be installed on phones and computers until the store apps are published: web manifest with the
  company name and Appearance primary colour, home-screen icons built from the uploaded favicon like the Flutter
  launcher icon (regular, maskable and Apple touch icon), and iOS home-screen meta tags.
- `sw.js` is registered on every page, adds an offline screen (pages themselves are never cached) and uses the new icon
  for push notifications.
- Install banner: **Install** button on Android/desktop Chrome and Edge, Add to Home Screen steps on iPhone/iPad.

## Mobile reports, charts, profile editing and a refreshed look (18 Nov 2026)
- Mobile API: `GET /reports?days=` returns KPIs and chart series (stock status, value or items by category, requests by
  status and over time, most issued items, issued value by project, expense claims by status), each limited to what
  the user may see; `PUT /me` lets users update their own name, e-mail (with current password) and phone (docs/API.md).
- Flutter app, Home: header band with the headline cards, an **Out of stock** list (first five, "See all" opens Stock
  filtered) and an Overview with the stock-status and requests-by-status charts.
- New **Reports** screen (More, and Home quick actions) with a 7 days / 30 days / 90 days / 12 months switch, KPI tiles
  and donut, bar and column charts drawn without extra packages, using a colour-blind-checked palette.
- **Profile**: redesigned page and a new Edit profile screen.
- Items: list rows and the item page now show a stock-state icon, a stock-level bar against the minimum, badges
  (including Out of stock on the item page), fact tiles, and each store's share of the balance.
- Look: tinted page background, cards with soft shadows and borders, white navigation bar, and darker page versus card
  in dark mode, so content stands out.
- Tests: `MobileReportsAndProfileTest` (5), Flutter `reports_profile_test` (3) and
  `dashboard_reports_profile_test` (4).

## Users & feedback on mobile, feedback module (17 Nov 2026)
- **Feedback** (web and mobile): anyone, including read-only auditors, can report a problem, ask a question or suggest
  something, with an optional 1-5 rating. Admins see all feedback (sidebar count of new items), set the status
  (new / in review / resolved / closed) and reply; admins are alerted to new feedback and the sender to replies.
- Mobile API: `/users` (list, options, show, create, update) for admins and `/feedback` endpoints (docs/API.md);
  `/me` exposes `can.manage_users` and `can.manage_feedback`; `/me` and `/branding` include `app_subtitle`.
- User account rules moved into the `UserAccounts` service (app/Services/UserAccounts.php), shared by the web Users
  pages and the API.
- Flutter app: the title everywhere (window/task switcher, splash, sign-in, home, About) is the system name set in
  Settings, with the tagline; new Users screens (admin: search, add, edit role/projects/status, reset password) and
  Feedback screens (send with rating, follow replies; admins reply) under More.
- Tests: `FeedbackTest` (7), Flutter `users_feedback_test` (11).

## Phase 6 – UAT, go-live & handover (13 Nov 2026)
- `cms:reconcile` (scheduled daily): stock ledger = balances per store × item, voucher totals/movements, returns ≤ issued, transfers, PO received/invoiced counters, invoice payments, paid claims.
- `cms:go-live-check`: production configuration, readiness data (admins, overall manager, storekeepers per store, no demo accounts), reconciliation and audit chain.
- Parallel race proof re-run on MariaDB with the multi-store code (6/6), followed by a clean reconciliation and audit-chain check.
- Documents: architecture & ERD (`docs/ARCHITECTURE.md`), UAT traceability for the 14 required scenarios with manual scripts and sign-off sheet, training plan, go-live runbook with data migration, secure handover and warranty (`docs/HANDOVER.md`).
- Tests: `GoLiveTest` (4).

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
