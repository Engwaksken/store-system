# Changelog

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
