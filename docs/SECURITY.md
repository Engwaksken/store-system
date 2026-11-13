# Security & Controls

This lists every control the application enforces and where it is tested. Run `php artisan test` to re-prove them.

## Access control
| Control | How | Evidence |
|---|---|---|
| Authentication | Session login, rate-limited; password reset by e-mail token; forced change after admin reset; no self-registration; deactivated users are logged out on their next request. | `AccessControlTest` |
| Authorization on every route | Gates/policies (`AppServiceProvider`, `app/Policies`) checked in every controller action, re-checked in the service layer for stock, approvals and money. | `AccessControlTest`, `AuditorAccessTest` (all ~70 mutating routes) |
| Row-level scoping | `visibleTo` scopes on projects, requests, expenses, claims, requisitions, POs, transfers; PM reports/exports limited to managed projects. | `test_project_visibility_is_role_scoped`, `test_exports_obey_row_level_permissions` |
| Store scoping | Storekeepers move stock only in assigned stores (`Store::assertOperable` inside every stock service). | `MultiStoreStockTest` |
| Read-only auditor | `auditor` role: every report, register and the audit log; no create/approve/post anywhere. | `AuditorAccessTest` |
| Admin is not a silent approver | Owner/Admin approves material requests only as the documented PM substitute (D2); cannot approve own PR/PO/claim/count; all admin actions are logged. | `ApprovalWorkflowTest`, `ProcurementTest`, `ProjectControlsTest` |

## Separation of duties (server-checked)
Requester ≠ PM approver ≠ Overall approver; storekeeper issues but never approves; transfer dispatcher ≠ receiver; stock counter ≠ count approver; PR requester ≠ approver; PO creator ≠ approver (owner threshold above a set value); invoice recorder ≠ exception approver; claimant ≠ claim approver. See `docs/ROLE_PERMISSION_MATRIX.md`.

## Integrity
| Control | How |
|---|---|
| No negative stock, no over-issue | Row locks (`lockForUpdate`) on request, lines, items and per-store balances in a fixed order; re-validation inside the transaction. Proven with 8 parallel processes on MariaDB (`docs/UAT_EVIDENCE.md`). |
| No double posting | One-time idempotency keys on receipts, GRNs, issues, transfers, returns and supplier payments; unique supplier invoice numbers per supplier; a claim can be paid once (unique `expenses.expense_claim_id`). |
| Stale approvals | Purchase orders carry `lock_version`; an approval from an out-of-date screen is refused. |
| Append-only ledger | `stock_movements`, vouchers, receipts, returns, adjustments, approvals and payments refuse update/delete at model level; corrections are new documents. |
| Tamper-evident audit log | Each `activity_logs` row stores `prev_hash` and `hash = HMAC-SHA256(prev_hash ‖ content, APP_KEY)`; writers serialize on `audit_chain_heads`. `php artisan cms:verify-audit` (scheduled daily 02:30) reports the first edited/deleted row. Keep `APP_KEY` secret and backed up separately: without it the chain cannot be verified, and with it an attacker with DB access could forge rows. |
| Money and quantities | DECIMAL columns and bcmath only; no floating-point arithmetic in postings. |

## Web hardening
- `SecurityHeaders` middleware: CSP (same-origin only), `X-Frame-Options: SAMEORIGIN`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS on HTTPS; authenticated pages are `Cache-Control: no-store`.
- CSRF on every form (Laravel default). Per-user limit of 300 requests/minute; tighter limits on login, password reset and posting routes.
- Uploads (expense proofs, claim receipts, supplier invoice scans) go to the private disk and are served only after an authorization check; type and size are validated.
- CSV exports neutralise spreadsheet formula injection; XLSX exports write text as inline strings (never formulas).
- PDF rendering (dompdf) has remote resources and PHP disabled.

## Not built (by agreement)
- **MFA** is optional in the brief and not built. If required, add TOTP for Owner/Admin and approvers as a change order.
- No SMS/WhatsApp, no bank integration, no public API.

## Operations
- Daily encrypted-at-rest backups depend on the host; `cms:backup` produces the dump (see `docs/DEPLOYMENT.md` §5–6 for the restore drill).
- Never commit `.env`; rotate `APP_KEY` only with a plan (it invalidates sessions and the audit-chain verification of older rows).
