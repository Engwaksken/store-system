# Changelog

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
