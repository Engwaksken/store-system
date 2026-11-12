# Database Schema (Phase 1)

Source of truth: `database/migrations/0001_01_01_000000_create_users_table.php` (users + role columns) and `database/migrations/2026_11_12_000100_create_cms_domain_tables.php`.

Precision: quantities `DECIMAL(14,3)`, money `DECIMAL(15,2)`, unit costs `DECIMAL(15,4)`. All arithmetic in PHP uses bcmath (`App\Support\Decimal`), never floats.
Foreign keys use `RESTRICT` on delete for anything with financial or stock history, so nothing auditable can be orphaned.

## Identity & configuration
| Table | Key columns | Notes |
|---|---|---|
| `users` | `role` (indexed), `is_active`, `must_change_password`, `phone` | Role values in `App\Enums\Role`. Users are deactivated, never deleted. |
| `password_reset_tokens`, `sessions` | Laravel defaults | |
| `app_settings` | `key` unique, `value` | Company name, address, phone, e-mail, voucher footer, logo path. |
| `document_sequences` | `sequence_key` unique (e.g. `IV-2026`), `last_number` | Incremented under `lockForUpdate`, giving unique document numbers `PREFIX-YYYY-00001`. Prefixes: MR (request), IV (issue voucher), GRN (receipt), OB (opening), ADJ (adjustment). |
| `activity_logs` | `user_id`, `action` (indexed), `subject_type/id`, `description`, `properties` JSON, `ip_address`, `created_at` | Insert-only (model blocks update/delete). |

## Projects
| Table | Key columns | Notes |
|---|---|---|
| `projects` | `code` unique, `name`, `client`, `location`, `start_date`, `end_date`, `status` (indexed), `budget`, `manager_id`→users (indexed), `created_by` | Status: planned, active, on_hold, completed, cancelled. Requests are allowed only on planned and active projects. |
| `project_user` | `project_id`, `user_id`, unique pair, `user_id` indexed | Staff assignment. A user is "assigned" if they are a member or the manager. |

## Inventory (single store)
| Table | Key columns | Notes |
|---|---|---|
| `inventory_categories` | `name` unique | |
| `inventory_units` | `name`, `abbreviation` unique | |
| `inventory_items` | `sku` unique, `category_id`, `unit_id`, `min_level`, `avg_cost`, `is_active` | `avg_cost` = weighted average, updated only by receipts. |
| `inventory_balances` | `item_id` **unique**, `on_hand` | One row per item, created automatically with the item. This is the row locked during issues. |
| `stock_receipts` | `receipt_no` unique, `type` (opening/purchase), `supplier_name`, `reference`, `received_on`, `total_value`, `received_by` | Immutable once posted. |
| `stock_receipt_lines` | `stock_receipt_id`, `item_id`, `qty`, `unit_cost`, `line_total` | Immutable. |
| `stock_adjustments` | `adjustment_no` unique, `item_id`, `qty_delta` (±), `reason`, `actor_id` | Immutable correction record. |
| `stock_movements` | `item_id`, `movement_type` (opening/receipt/issue/adjustment), `qty_delta`, `unit_cost`, `balance_after`, `project_id` nullable, `source_type`, `source_id`, `actor_id`, `created_at` | **Append-only ledger.** Indexed on (item_id,id), project_id, (source_type,source_id). Invariant: `SUM(qty_delta)` per item = `inventory_balances.on_hand`. |

## Requisitions & approvals
| Table | Key columns | Notes |
|---|---|---|
| `material_requests` | `request_no` unique, `project_id`, `requester_id`, `project_manager_id` (snapshot at submit), `version`, `status` (indexed), `purpose`, `needed_on`, `submitted_at` | Indexes: (project_id,status), requester_id, project_manager_id. `status` and `version` are not mass-assignable. |
| `material_request_lines` | `material_request_id`, `item_id` (unique per request), `requested_qty`, `pm_approved_qty`, `final_approved_qty`, `issued_qty` | Requested, approved and issued quantities are kept separately. Invariant: `issued_qty ≤ final_approved_qty ≤ pm_approved_qty ≤ requested_qty`. |
| `material_approvals` | `material_request_id`, `version`, `step` (pm/overall), `approver_id`, `decision`, `reason`, `was_substitute`, `lines_snapshot` JSON, `decided_at` | **Unique (request, version, step)**: one decision per step per version. Immutable. |

## Issuance
| Table | Key columns | Notes |
|---|---|---|
| `material_issues` | `issue_no` unique (voucher), `material_request_id`, `project_id` (indexed), `issued_by`, `recipient_name`, `issued_at`, `total_value`, `notes` | Immutable. |
| `material_issue_lines` | `material_issue_id`, `material_request_line_id`, `item_id`, `qty`, `unit_cost_snapshot`, `line_value` | Immutable. Cost is snapshotted at issue time. |
| `issue_acknowledgments` | `material_issue_id` **unique**, `acknowledged_by`, `recipient_name`, `remarks`, `acknowledged_at` | One acknowledgment per voucher. |

## Expenses
| Table | Key columns | Notes |
|---|---|---|
| `expense_categories` | `name` unique, `is_active` | |
| `expenses` | `project_id`, `category_id`, `incurred_on`, `amount`, `payment_mode`, `reference`, `description`, `receipt_path` (private disk), `recorded_by` | Index (project_id, incurred_on). Cash costs only; store purchases are excluded (MVP_SCOPE D9). |

## Project cost formula (reports)
`project cost = Σ material_issues.total_value (project) + Σ expenses.amount (project)`. Stock receipts are never added to project cost.
