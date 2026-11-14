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

**User**
```json
{
  "id": 7, "name": "Brian Okello", "email": "brian@example.com", "phone": "+256...",
  "role": "project_manager", "role_label": "Project Manager",
  "must_change_password": false,
  "currency": "UGX", "currency_decimals": 0,
  "company_name": "Kemmy Tech",
  "can": {
    "approve_requests": true,      // sees the Approvals tab
    "create_request": true,        // may raise material requests
    "submit_claim": true,          // may submit expense claims
    "decide_claims": true,         // may approve/reject claims (still checked per claim)
    "view_stock_value": false,     // avg cost / stock value visible
    "view_claims": true
  }
}
```

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

### `GET /items` — paginated. Filters: `q` (SKU / name), `store_id` (on_hand for that store only), `low=1`, `active=1`.
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

## Deploying the API to store.kemmytech.com

1. Pull the code, then `composer install --no-dev --optimize-autoloader` (adds `laravel/sanctum`).
2. `php artisan migrate --force` (creates `personal_access_tokens`).
3. `php artisan config:cache && php artisan route:cache`.
4. Check: `curl -s https://store.kemmytech.com/api/v1/me` must return `{"message":"Unauthenticated."}` with status 401.
   If it returns HTML, the web server is not routing `/api/*` to `public/index.php`.
5. Optional: `SANCTUM_EXPIRATION` (minutes, default 43200 = 30 days) in `.env`.

A native app does not need CORS. Signing in from the app is logged in the activity log (`auth.api_login`);
deactivating a user revokes their app tokens on their next request; changing the password in the app signs out the user's other devices.
