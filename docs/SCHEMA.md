# Database Schema

Source of truth: the files in `database/migrations/` (Phase 1: `..._000100_create_cms_domain_tables.php`; Phase 2 stores: `..._000200_add_multiple_stores_and_transfers.php`; Phase 3 procurement: `..._000300_create_procurement_tables.php`).

Precision: quantities `DECIMAL(14,3)`, money `DECIMAL(15,2)`, unit costs `DECIMAL(15,4)`. All arithmetic in PHP uses bcmath (`App\Support\Decimal`), never floats.
Foreign keys use `RESTRICT` on delete for anything with financial or stock history, so nothing auditable can be orphaned.

## Identity & configuration
| Table | Key columns | Notes |
|---|---|---|
| `users` | `role` (indexed), `is_active`, `must_change_password`, `phone` | Role values in `App\Enums\Role`. Users are deactivated, never deleted. |
| `password_reset_tokens`, `sessions` | Laravel defaults | |
| `app_settings` | `key` unique, `value` | Company name, address, phone, e-mail, voucher footer, logo path. |
| `document_sequences` | `sequence_key` unique (e.g. `IV-2026`), `last_number` | Incremented under `lockForUpdate`, giving unique document numbers `PREFIX-YYYY-00001`. Prefixes: MR (request), IV (issue voucher), GRN (receipt), OB (opening), ADJ (adjustment), TR (transfer), RET (return), SC (stock count), PR (purchase requisition), PO (purchase order), PI (supplier invoice), PAY (supplier payment). |
| `activity_logs` | `user_id`, `action` (indexed), `subject_type/id`, `description`, `properties` JSON, `ip_address`, `created_at` | Insert-only (model blocks update/delete). |

## Projects
| Table | Key columns | Notes |
|---|---|---|
| `projects` | `code` unique, `name`, `client`, `location`, `start_date`, `end_date`, `status` (indexed), `budget`, `manager_id`→users (indexed), `created_by` | Status: planned, active, on_hold, completed, cancelled. Requests are allowed only on planned and active projects. |
| `project_user` | `project_id`, `user_id`, unique pair, `user_id` indexed | Staff assignment. A user is "assigned" if they are a member or the manager. |

## Stores (Phase 2)
| Table | Key columns | Notes |
|---|---|---|
| `stores` | `code` unique, `name` unique, `type` (central/site), `project_id` nullable, `location`, `is_active` | Store #1 `MAIN` is created by the migration and holds all pre-Phase-2 history. A site store (`project_id` set) can only issue to its own project. |
| `store_user` | `store_id`, `user_id`, unique pair | Storekeepers who may operate the store. Existing storekeepers are assigned to MAIN by the migration. Admin may operate every active store. |

## Inventory
| Table | Key columns | Notes |
|---|---|---|
| `inventory_categories` | `name` unique | |
| `inventory_units` | `name`, `abbreviation` unique | |
| `inventory_items` | `sku` unique, `category_id`, `unit_id`, `min_level`, `avg_cost`, `is_active` | `avg_cost` = company-wide weighted average, recomputed by receipts and returns. Transfers move stock at the current average. `min_level` is compared with company-wide stock. |
| `inventory_balances` | `store_id`, `item_id`, **unique (store_id, item_id)**, `on_hand` | One row per store × item, created on first movement (`InventoryBalance::lockRows`). This is the row locked during issues, transfers, returns and adjustments. |
| `stock_receipts` | `receipt_no` unique, `type` (opening/purchase), `store_id`, `supplier_name`, `reference`, `received_on`, `total_value`, `received_by`, `idempotency_key` unique | Immutable once posted. Opening balance is allowed once per item **per store**. |
| `stock_receipt_lines` | `stock_receipt_id`, `item_id`, `qty`, `unit_cost`, `line_total` | Immutable. |
| `stock_adjustments` | `adjustment_no` unique, `item_id`, `store_id`, `stock_count_id` nullable, `qty_delta` (±), `reason`, `actor_id` | Immutable correction record. Count variances carry the `stock_count_id`. |
| `stock_movements` | `item_id`, `store_id`, `movement_type`, `qty_delta`, `unit_cost`, `balance_after` (store balance), `project_id` nullable, `source_type`, `source_id`, `actor_id`, `created_at` | **Append-only ledger.** Types: opening, receipt, issue, return, adjustment, transfer_out, transfer_in, transfer_cancel. Invariant: `SUM(qty_delta)` per (store, item) = `inventory_balances.on_hand` and the last row's `balance_after` equals it. |
| `stock_transfers` | `transfer_no` unique, `from_store_id`, `to_store_id`, `status` (in_transit/received/cancelled), `dispatched_by/at`, `received_by/at`, `notes`, `receive_notes`, `idempotency_key` unique | Changes are refused once the status is closed (model guard). |
| `stock_transfer_lines` | `stock_transfer_id`, `item_id` (unique per transfer), `qty` dispatched, `received_qty`, `unit_cost`, `shortfall_reason` | `received_qty` is written once. Invariant: dispatched = received + shortfall. |
| `stock_returns` | `return_no` unique, `material_issue_id`, `project_id`, `store_id`, `received_by`, `returned_by_name`, `reason`, `total_value`, `returned_at`, `idempotency_key` unique | Immutable. Credits the project at the voucher's cost. |
| `stock_return_lines` | `stock_return_id`, `material_issue_line_id`, `item_id`, `qty`, `unit_cost`, `line_value` | Immutable. Σ returned per voucher line ≤ the line's issued qty (checked under a lock on the voucher). |
| `stock_counts` | `count_no` unique, `store_id`, `status` (draft/submitted/approved/rejected), `counted_by`, `submitted_at`, `decided_by/at`, `decision_reason`, `notes` | Closed counts cannot change. |
| `stock_count_lines` | `stock_count_id`, `item_id` (unique per count), `system_qty` (frozen at creation), `counted_qty`, `reason` | Variance = counted − system; posted as an adjustment on approval. |

## Procurement (Phase 3)
| Table | Key columns | Notes |
|---|---|---|
| `suppliers` | `code` unique, `name` unique, contact, `tin`, `payment_terms`, `is_active` | Deactivated, never deleted. |
| `purchase_requisitions` | `pr_no` unique, `project_id` nullable, `store_id` (deliver to), `requested_by`, `status`, `purpose`, `needed_on`, `estimated_total`, `submitted_at`, `decided_by/at`, `decision_reason` | Status: draft, submitted, approved, rejected, ordered, cancelled. |
| `purchase_requisition_lines` | `purchase_requisition_id`, `item_id` (unique per PR), `qty`, `est_unit_cost` | |
| `supplier_quotes` | `purchase_requisition_id`, `supplier_id` (unique pair), `quote_ref`, `quoted_on`, `valid_until`, `total`, `is_selected`, `selection_reason`, `recorded_by` | |
| `supplier_quote_lines` | `supplier_quote_id`, `purchase_requisition_line_id`, `unit_price` | |
| `purchase_orders` | `po_no` unique, `supplier_id`, `purchase_requisition_id`, `supplier_quote_id`, `project_id`, `store_id`, `status`, `order_date`, `expected_on`, `total`, `terms`, `created_by`, `decided_by/at`, `decision_reason`, `lock_version` | Status: pending_approval, approved, rejected, partially_received, received, closed, cancelled. `lock_version` = optimistic lock for approvals. |
| `purchase_order_lines` | `purchase_order_id`, `item_id` (unique per PO), `qty`, `unit_price`, `line_total`, `received_qty`, `invoiced_qty` | Counters change only under a PO row lock. Invariant: `received_qty ≤ qty`. |
| `stock_receipts` (+) | `supplier_id`, `purchase_order_id` | A GRN is a stock receipt linked to the PO. `stock_receipt_lines.purchase_order_line_id` links each line. |
| `purchase_invoices` | `pi_no` unique, `supplier_id` + `supplier_invoice_no` **unique**, `purchase_order_id`, dates, `subtotal`, `tax`, `total`, `amount_paid`, `match_status`, `match_notes`, `payment_status`, `recorded_by`, `exception_approved_by/at`, `exception_reason`, `attachment_path` (private disk) | match: matched / exception / exception_approved. payment: unpaid / partially_paid / paid. Invariant: `amount_paid ≤ total`. |
| `purchase_invoice_lines` | `purchase_invoice_id`, `purchase_order_line_id`, `qty`, `unit_price`, `line_total` | Immutable. |
| `supplier_payments` | `payment_no` unique, `purchase_invoice_id`, `amount`, `paid_on`, `method`, `reference`, `recorded_by`, `idempotency_key` unique | Immutable record of a payment made outside the system. |

## Requisitions & approvals
| Table | Key columns | Notes |
|---|---|---|
| `material_requests` | `request_no` unique, `project_id`, `requester_id`, `project_manager_id` (snapshot at submit), `version`, `status` (indexed), `purpose`, `needed_on`, `submitted_at` | Indexes: (project_id,status), requester_id, project_manager_id. `status` and `version` are not mass-assignable. |
| `material_request_lines` | `material_request_id`, `item_id` (unique per request), `requested_qty`, `pm_approved_qty`, `final_approved_qty`, `issued_qty` | Requested, approved and issued quantities are kept separately. Invariant: `issued_qty ≤ final_approved_qty ≤ pm_approved_qty ≤ requested_qty`. |
| `material_approvals` | `material_request_id`, `version`, `step` (pm/overall), `approver_id`, `decision`, `reason`, `was_substitute`, `lines_snapshot` JSON, `decided_at` | **Unique (request, version, step)**: one decision per step per version. Immutable. |

## Issuance
| Table | Key columns | Notes |
|---|---|---|
| `material_issues` | `issue_no` unique (voucher), `material_request_id`, `project_id` (indexed), `store_id`, `issued_by`, `recipient_name`, `issued_at`, `total_value`, `notes`, `idempotency_key` unique | Immutable. |
| `material_issue_lines` | `material_issue_id`, `material_request_line_id`, `item_id`, `qty`, `unit_cost_snapshot`, `line_value` | Immutable. Cost is snapshotted at issue time. |
| `issue_acknowledgments` | `material_issue_id` **unique**, `acknowledged_by`, `recipient_name`, `remarks`, `acknowledged_at` | One acknowledgment per voucher. |

## Expenses
| Table | Key columns | Notes |
|---|---|---|
| `expense_categories` | `name` unique, `is_active` | |
| `expenses` | `project_id`, `category_id`, `incurred_on`, `amount`, `payment_mode`, `reference`, `description`, `receipt_path` (private disk), `recorded_by` | Index (project_id, incurred_on). Cash costs only; store purchases are excluded (MVP_SCOPE D9). |

## Project cost formula (reports)
`project cost = Σ material_issues.total_value − Σ stock_returns.total_value + Σ expenses.amount` (per project). Stock receipts and transfers are never added to project cost.

## Idempotency
Receipts, issues, transfers and returns accept a one-time `idempotency_key` from the form (`partials/idem`). A repeated post returns the first document; concurrent duplicates are stopped by the unique index (`App\Support\Idempotency`).
