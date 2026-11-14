# Material Request — Approval State Machine

Implemented in `app/Services/RequestWorkflow.php` (approvals) and `app/Services/IssueService.php` (issuance). The `status` column is never accepted from client input.

```
           submit                PM approve              Overall approve
 DRAFT ───────────▶ SUBMITTED ───────────▶ PM_APPROVED ───────────────▶ OVERALL_APPROVED
   ▲                    │                      │                              │ issue (partial)
   │                    │ PM reject            │ Overall reject               ▼
   │                    ▼                      ▼                       PARTIALLY_ISSUED
   │               PM_REJECTED          OVERALL_REJECTED                      │ issue (rest)
   │                    │                      │                              ▼
   └──── revise (version+1, approvals reset) ──┘                        FULLY_ISSUED
DRAFT ── cancel ──▶ CANCELLED      (also from SUBMITTED, by requester, before any decision)
```

## Transitions and guards

| From | Action | Actor | Guards | To |
|---|---|---|---|---|
| DRAFT | edit lines | requester | still assigned to project | DRAFT |
| SUBMITTED / PM_APPROVED | edit lines (pending edit) | requester | still assigned; project has a PM; `version++`, approved qtys reset, PM notified | SUBMITTED |
| DRAFT | submit | requester | ≥1 line, qty > 0, still assigned; snapshot `project_manager_id` | SUBMITTED |
| SUBMITTED | pm approve | project's PM (snapshot), or admin when requester = PM | approver ≠ requester; 0 ≤ pm_qty ≤ requested; at least one qty > 0 | PM_APPROVED |
| SUBMITTED | pm reject | same as above | reason required | PM_REJECTED |
| PM_APPROVED | overall approve | overall_manager | ≠ requester; ≠ PM-step approver; 0 ≤ final ≤ pm_qty; at least one > 0 | OVERALL_APPROVED |
| PM_APPROVED | overall reject | overall_manager | same independence; reason required | OVERALL_REJECTED |
| OVERALL_APPROVED / PARTIALLY_ISSUED | issue | storekeeper | per line: qty ≤ final − issued; qty ≤ on_hand (locked rows) | PARTIALLY_ISSUED or FULLY_ISSUED |
| PM_REJECTED / OVERALL_REJECTED | revise | requester | — ; `version++`, approved qtys reset | DRAFT |
| DRAFT / SUBMITTED | cancel | requester or admin | no decision yet on current version | CANCELLED |

**Never issuable:** DRAFT, SUBMITTED, PM_APPROVED, PM_REJECTED, OVERALL_REJECTED, CANCELLED, FULLY_ISSUED.

## Decision audit
- `material_approvals` rows are insert-only. The model blocks update and delete.
- Unique key `(material_request_id, version, step)` allows one decision per step per version.
- Each row stores approver, decision, reason, `decided_at`, `was_substitute`, and a JSON snapshot of approved line quantities.

## Concurrency
- Every transition runs in `DB::transaction` and re-reads the request with `lockForUpdate()`. Two approvers clicking at the same moment can't both succeed, and the loser gets "request is no longer in state X".
- Issuance locks the request, its lines and the affected `inventory_balances` rows in ascending `item_id` order to avoid deadlocks. The checks run against the locked values. Stock decrements, the ledger insert, the voucher and the status update all commit together or not at all.

**Pending edits.** The requester may edit until the Overall Manager decides. Editing a SUBMITTED or PM_APPROVED request makes a new version back at the PM step, so a PM approval of the old lines never carries over. The decision forms send the version they were rendered from; a decision on an older version is refused ("the requester changed this request"), so nobody approves lines they have not seen. Decisions on earlier versions stay in the history.
