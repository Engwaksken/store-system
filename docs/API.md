# Mobile API (v1)

JSON API used by the Flutter app in `mobile/`. Every rule of the web app applies: the API calls the same gates
and workflow services, so the app can never do more than the same user could on the website.

- **Base URL:** `https://store.kemmytech.com/api/v1`
- **Auth:** Laravel Sanctum personal access tokens. Send `Authorization: Bearer <token>` and `Accept: application/json` on every call.
- **Money and quantities** are strings with fixed decimals (`"125000.00"`, `"12.500"`), never floats. Format them in the app.
- **Dates** are `YYYY-MM-DD`; timestamps are ISO 8601 with offset (`2026-11-14T09:30:00+03:00`).
- **Currency:** `GET /me` returns `currency` (e.g. `"UGX"`) and `currency_decimals`.

## Errors

| Status | When | Body |
|---|---|---|
| 401 | missing / expired / revoked token | `{"message": "Unauthenticated."}` |
| 403 | not allowed (role, project, separation of duties), account deactivated, or password change required | `{"message": "...", "code": "forbidden" \| "deactivated" \| "password_change_required"}` |
| 404 | record not found or not visible to the user | `{"message": "Not found."}` |
| 409 | workflow refused the action (wrong status, someone changed the record since you loaded it) | `{"message": "Request MR-0001 is Approved; this action is not allowed now."}` |
| 422 | validation | `{"message": "...", "errors": {"field": ["..."]}}` |
| 429 | too many requests | `{"message": "Too Many Attempts."}` |

When `must_change_password` is true, every endpoint except `GET /me`, `POST /auth/change-password` and `POST /auth/logout`
returns 403 with `code: "password_change_required"`. The app must show the change-password screen.

## Pagination

List endpoints take `?page=N` (and `per_page`, max 50, default 20) and return:

```json
{ "data": [ ... ], "meta": { "current_page": 1, "last_page": 4, "per_page": 20, "total": 73 } }
```

## Auth

### `POST /auth/login`
Body: `{"email": "...", "password": "...", "device_name": "Pixel 8"}` → `200`
```json
{ "token": "1|abc...", "user": { ...User } }
```
Wrong credentials → 422 on `email`. Deactivated → 403 `deactivated`. Rate-limited (10/min per email+IP).

### `POST /auth/logout` → `204` (revokes the current token)

### `POST /auth/change-password`
Body: `{"current_password", "password", "password_confirmation"}`. New password: min 8, letters and numbers, different
from the current one. → `200 { "user": {...User} }` (clears `must_change_password`).

### `GET /me` → `200 { "user": {...User} }`

### `PUT /me` (throttle 10/min)
The signed-in user updates their own profile. Body: `{"name", "email", "phone?", "current_password?"}`.
`current_password` is required only when the e-mail changes; the e-mail must not belong to another user. Role, projects
and status cannot be changed here. → `200 { "user": {...User} }`; logged as `auth.profile_updated`.

**User**
```json
{
  "id": 7, "name": "Brian Okello", "email": "brian@example.com", "phone": "+256...",
  "role": "project_manager", "role_label": "Project Manager",
  "must_change_password": false,
  "currency": "UGX", "currency_decimals": 0,
  "company_name": "Kemmy Tech",      // the system name: the app uses it as its title
  "app_subtitle": "Store & Projects",
  "can": {
    "approve_requests": true,      // sees the Approvals tab
    "create_request": true,        // may raise material requests
    "submit_claim": true,          // may submit expense claims
    "decide_claims": true,         // may approve/reject claims (still checked per claim)
    "view_stock_value": false,     // avg cost / stock value visible
    "view_claims": true,
    "manage_users": false,         // admin: Users screens (/users)
    "manage_feedback": false       // admin: sees all feedback and replies
  }
}
```

### `GET /branding` (public) → `{ "company_name", "app_subtitle", "logo_url", "favicon_url" }`
The system name and tagline set in the web app (Settings). The app shows `company_name` as its title.

## Dashboard

### `GET /dashboard`
```json
{
  "cards": [ { "key": "awaiting_me", "label": "Waiting for my approval", "value": 3, "tone": "gold" }, ... ],
  "awaiting_me": [ ...RequestSummary (max 5) ],
  "unread_notifications": 2
}
```
`tone`: `navy | gold | bad | ok | ""`. `key` is one of `awaiting_me`, `my_open_requests`, `ready_to_issue`, `low_stock`, `claims_awaiting`, `claims_unpaid`; cards depend on role (requests waiting for me, my open requests, low-stock items,
claims awaiting approval, approved claims unpaid).

## Reports

### `GET /reports?days=30` (throttle 30/min)
Chart-ready summaries for the app's Home and Reports screens. `days`: `7 | 30 | 90 | 365` (anything else → 30).
```json
{
  "period": { "days": 30, "from": "2026-10-20", "to": "2026-11-18", "label": "Last 30 days" },
  "kpis": [ { "key": "out_of_stock", "label": "Out of stock", "value": 3, "format": "count", "tone": "bad" }, ... ],
  "charts": [
    { "key": "stock_status", "title": "Stock status", "subtitle": "...", "type": "donut", "format": "count",
      "series": [ { "label": "In stock", "value": 16, "tone": "ok" }, ... ] }, ...
  ]
}
```
`type`: `donut | bar | column`; `format`: `count` (integer values) `| money | qty` (decimal strings). Only what the user
may see is included, with the same rules as the web lists: stock status and items/value by category for everyone
(value only with `view-stock-value`); requests by status and over time from requests visible to the user; most issued
items for report users (`view-reports`), issued value by project for finance report users; expense claims by status from
claims visible to the user. Project managers' issue charts cover their managed projects only.

## Material requests

**RequestSummary**
```json
{
  "id": 12, "request_no": "MR-2026-0012", "version": 1,
  "status": "submitted", "status_label": "Awaiting PM", "status_tone": "pending",
  "project": { "id": 1, "code": "PRJ-001", "name": "Kira Road" },
  "requester": { "id": 5, "name": "Jane" },
  "purpose": "Slab casting", "needed_on": "2026-11-20",
  "lines_count": 3, "submitted_at": "...", "created_at": "..."
}
```
`status`: `draft | submitted | pm_approved | pm_rejected | overall_approved | overall_rejected | partially_issued | fully_issued | cancelled`.
`status_tone`: `pending | approved | rejected | partial | muted` (badge colour).

**RequestDetail** = RequestSummary plus:
```json
{
  "project_manager": { "id": 3, "name": "PM name" } | null,
  "lines": [ {
      "id": 31, "item": { "id": 4, "sku": "CEM-425", "name": "Cement 42.5R", "unit": "bag" },
      "requested_qty": "20.000", "pm_approved_qty": null, "final_approved_qty": null, "issued_qty": "0.000",
      "on_hand": "140.000", "notes": null
  } ],
  "approvals": [ { "step": "pm", "version": 1, "decision": "approved", "reason": null,
                   "approver": { "id": 3, "name": "..." }, "was_substitute": false, "decided_at": "..." } ],
  "can": {
    "decide": true,              // waiting for this user's decision now
    "decision_step": "pm",       // "pm" | "overall" | null
    "max_qty_field": "requested_qty", // ceiling for approved qty: requested_qty (pm) or pm_approved_qty (overall)
    "cancel": false
  }
}
```

### `GET /approvals` — requests waiting for **my** decision (paginated RequestSummary, oldest first). `?q=` searches number / purpose.

### `GET /requests` — requests visible to me (paginated RequestSummary, newest first). Filters: `status`, `project_id`, `q`.

### `GET /requests/{id}` → `{ "data": RequestDetail }`

### `POST /requests/{id}/decision`
Body:
```json
{ "decision": "approved" | "rejected", "reason": "required when rejected",
  "qty": { "31": "18", "32": "0" },   // optional; line id → approved qty, 0..ceiling; omitted lines get the full ceiling
  "version": 1 }                       // the version the user looked at; a newer version → 409
```
The server decides at whichever step the user may act (PM or overall). → `200 { "data": RequestDetail }`.

### `POST /requests` — create
Body:
```json
{ "project_id": 1, "purpose": "...", "needed_on": "2026-11-20",
  "lines": [ { "item_id": 4, "requested_qty": "20", "notes": "optional" } ],
  "submit": true }   // true: submit to the PM; false: save as draft
```
→ `201 { "data": RequestDetail }`.

### `POST /requests/{id}/cancel` → `200 { "data": RequestDetail }` (draft or with the PM; requester or admin).

### `GET /requests/options` → `{ "projects": [ { "id", "code", "name" } ] }` — projects the user may request for.

## Stock

**Item**
```json
{ "id": 4, "sku": "CEM-425", "name": "Cement 42.5R", "category": "Cement & aggregates", "unit": "bag",
  "on_hand": "140.000", "min_level": "20.000", "low": false, "is_active": true,
  "avg_cost": "32000.00" }   // avg_cost only when can.view_stock_value, otherwise absent
```

### `GET /items` — paginated. Filters: `q` (SKU / name), `store_id` (on_hand for that store only), `low=1`, `out=1` (active items with nothing on hand, in `store_id` if given, else in all stores), `active=1`. Each item has `out_of_stock: bool`. The dashboard adds an `out_of_stock` card.
### `GET /items/{id}` → `{ "data": Item + "balances": [ { "store": { "id", "code", "name" }, "on_hand": "..." } ] }`
### `GET /stores` → `{ "data": [ { "id", "code", "name" } ] }`

## Expense claims

**Claim**
```json
{ "id": 9, "claim_no": "CLM-2026-0009", "status": "submitted", "status_label": "Awaiting approval", "status_tone": "pending",
  "project": { "id", "code", "name" }, "claimant": { "id", "name" }, "category": { "id", "name" },
  "cost_code": { "id", "code", "name" } | null,
  "incurred_on": "2026-11-12", "amount": "45000.00", "description": "...",
  "has_receipt": true, "receipt_url": "https://.../api/v1/claims/9/receipt" | null,
  "decision_reason": null, "updated_at": "...",
  "can": { "decide": true, "edit": false, "delete": false } }
```

### `GET /claims` — claims visible to me (paginated). Filters: `status`, `project_id`, `q`.
### `GET /claims/{id}` → `{ "data": Claim }`
### `GET /claims/{id}/receipt` → the receipt file (auth required).
### `POST /claims` — `multipart/form-data`: `project_id`, `category_id`, `cost_code_id?`, `incurred_on`, `amount`, `description`, `receipt?` (pdf/jpg/png, max 4 MB) → `201 { "data": Claim }`.
### `POST /claims/{id}/decision` — `{ "decision": "approved" | "rejected", "reason": "required when rejected", "seen_at": "<updated_at the user saw>" }` → `200 { "data": Claim }`; edited since → 409.
### `GET /claims/options` → `{ "projects": [...], "categories": [ { "id", "name" } ], "cost_codes": [ { "id", "code", "name" } ] }`

## Notifications

**Notification**
```json
{ "id": "uuid", "subject": "Request MR-0012 needs your approval", "body": "...", "kind": "info | warn | bad | ok",
  "read": false, "created_at": "...",
  "target": { "type": "request" | "claim" | null, "id": 12 } }   // where to navigate in the app, when known
```
### `GET /notifications` — paginated, newest first; `?unread=1`.
### `POST /notifications/{id}/read` → `204`
### `POST /notifications/read-all` → `204`

## Users (admin only, `can.manage_users`)

Same rules as the web Users pages: users are deactivated, never deleted; a new Staff account needs a project; an
admin cannot deactivate or demote themselves (422 on `role`). Everyone else gets 403.

**Account**
```json
{ "id": 12, "name": "Sam", "email": "sam@example.com", "phone": null,
  "role": "staff", "role_label": "Site Clerk", "profile_label": "Staff", "custom_role_id": 3,
  "is_active": true, "must_change_password": true, "projects_count": 1, "created_at": "...",
  "projects": [ { "id", "code", "name" } ] }      // detail and save responses only
```
### `GET /users` — paginated Account, by name. Filters: `q` (name / e-mail / phone), `role`, `status=active|inactive`.
### `GET /users/options[?user_id=]` → `{ "roles": [ { "value", "label" } ], "custom_roles": [ { "id", "name", "permission_profile" } ], "projects": [ { "id", "code", "name", "status" } ], "stats": { "total", "active", "inactive" } }`
### `GET /users/{id}` → `{ "data": Account }`
### `POST /users` / `PUT /users/{id}`
Body: `{ "name", "email", "phone?", "role", "custom_role_id?", "is_active", "password?", "reset_password?" (PUT), "project_ids": [] }`
→ `201` / `200 { "data": Account, "credentials_emailed": true | false | null, "temporary_password": null | "..." }`.
A temporary password is e-mailed when the account is created or the password is reset; `temporary_password` is only
returned when that e-mail could not be sent, so the admin can hand it over once.

## Feedback

Everyone sends feedback and follows their own; admins (`can.manage_feedback`) see all of it, set the status and reply.
Admins are notified of new feedback; the sender is notified of a reply or status change.

**Feedback**
```json
{ "id": 4, "reference": "FB-00004", "category": "bug", "category_label": "Problem / bug",
  "subject": "...", "message": "...", "rating": 4,                      // 1-5 or null
  "source": "web | mobile", "app_version": "1.0.0+1", "device": "Pixel 8", // version/device: admins only
  "status": "new | in_review | resolved | closed", "status_label": "New", "status_tone": "pending",
  "user": { "id", "name" }, "response": null, "responder": null, "responded_at": null,
  "created_at": "...", "updated_at": "...", "can_respond": false }
```
### `GET /feedback` — paginated, newest first. Filters: `status`, `category`, `q`, `scope=mine` (admins: only their own).
### `GET /feedback/options` → `{ "categories": [ { "value", "label" } ], "statuses": [ { "value", "label" } ] }`
### `POST /feedback` — `{ "category", "subject", "message", "rating?", "app_version?", "device?" }` → `201 { "data": Feedback }` (10/min).
### `GET /feedback/{id}` → `{ "data": Feedback }` (sender or admin, else 403)
### `POST /feedback/{id}/respond` — admin: `{ "status", "response?" }` → `200 { "data": Feedback }`

## Store operations and purchasing

Same gates and services as the web pages. Stock prices (`unit_cost`, `line_value`, `line_total`, `total_value`) appear
only when `can.view_stock_value` (Owner/Admin, Overall Manager). New `can` flags on the user: `issue_stock`,
`view_issues`, `manage_stock`, `view_receipts`, `view_transfers`, `create_transfer`, `view_returns`, `view_purchasing`,
`decide_purchasing`. Request detail has `can.issue`. `GET /items?in_stock=1` lists items with stock on hand.

| Method | Path | Notes |
|---|---|---|
| GET | `/store-ops/options` | `operable_stores` (this user operates), `active_stores` (transfer destinations) |
| GET | `/issues/queue` | Storekeeper: requests with both approvals, oldest first (request summaries) |
| GET | `/issues?q=&unacknowledged=1` | Issue vouchers the user may see |
| GET | `/issues/{id}` | Voucher with lines, acknowledgment, returns, `can.acknowledge`, `can.return` |
| POST | `/issues/{id}/acknowledge` | `{recipient_name?, remarks?}` |
| POST | `/issues/{id}/return` | `{store_id, qty: {issue_line_id: qty}, returned_by_name, reason, idempotency_key?}` |
| GET | `/requests/{id}/issue?store_id=` | Issue form: `lines` (outstanding, on_hand, max_issue), `shortfall` (short_qty, restock_qty), `open_restock` |
| POST | `/requests/{id}/issue` | `{store_id, qty: {request_line_id: qty}, recipient_name, notes?, idempotency_key?}` → `{data: voucher, restock: {id, pr_no}\|null, replayed}` |
| POST | `/requests/{id}/restock` | `{store_id}`: send the shortfall to the Overall Manager without issuing → `{data: {id, pr_no}\|null, message}` |
| GET/POST | `/receipts`, GET `/receipts/{id}` | POST `{store_id, received_on, supplier_name, reference?, notes?, lines: [{item_id, qty, unit_cost}], idempotency_key?}` |
| POST | `/adjustments` | `{store_id, item_id, qty_delta (negative removes), reason (5+ chars)}` → `{data: {adjustment_no, on_hand}}` |
| GET/POST | `/transfers?status=`, GET `/transfers/{id}` | POST `{from_store_id, to_store_id, notes?, lines: [{item_id, qty}], idempotency_key?}` |
| POST | `/transfers/{id}/receive` | `{received: {line_id: qty}, reason: {line_id: text}, receive_notes?}` (destination store, not the dispatcher) |
| POST | `/transfers/{id}/cancel` | `{reason}` |
| GET | `/returns`, `/returns/{id}` | |
| GET | `/purchase-requisitions?status=&restock=1`, `/purchase-requisitions/{id}` | `is_restock`, `material_request` |
| POST | `/purchase-requisitions/{id}/submit`, `/purchase-requisitions/{id}/decision` | decision `{decision: approved\|rejected, reason (required to reject)}` |
| GET | `/purchase-orders?status=`, `/purchase-orders/{id}` | detail has `lock_version` |
| POST | `/purchase-orders/{id}/decision` | `{decision, reason?, lock_version}`; a stale version is refused |

**Restocking.** When an approved request asks for more than the store holds, issue what is in stock. For each line still
short, a purchase requisition (status `submitted`, raised by the storekeeper, linked by `material_request`) goes to the
Owner/Admin and Overall Manager with quantity = max(balance not in stock, the item's minimum level). Items already on an
open restock requisition for the same request are not asked for twice.

**Profile photo.** `POST /me/avatar` (multipart `avatar`: JPG / PNG / WebP, max 3 MB) and `DELETE /me/avatar` return
`{user}`; the user object has `avatar_url` (null when none).

**Restock tracking.** Request detail has `restock`: `[{pr_no, status, status_label, status_tone, stage, orders: [{po_no,
status_label}], updated_at}]`, where `stage` is a plain-language step for the requester. Dashboard cards include
`issued_vouchers_month`, `issued_items_month` and `issues_unacknowledged`.

## Deploying the API to store.kemmytech.com

1. Pull the code, then `composer install --no-dev --optimize-autoloader` (adds `laravel/sanctum`).
2. `php artisan migrate --force` (creates `personal_access_tokens`).
3. `php artisan config:cache && php artisan route:cache`.
4. Check: `curl -s https://store.kemmytech.com/api/v1/me` must return `{"message":"Unauthenticated."}` with status 401.
   If it returns HTML, the web server is not routing `/api/*` to `public/index.php`.
5. Optional: `SANCTUM_EXPIRATION` (minutes, default 43200 = 30 days) in `.env`.

A native app does not need CORS. Signing in from the app is logged in the activity log (`auth.api_login`);
deactivating a user revokes their app tokens on their next request; changing the password in the app signs out the user's other devices.
