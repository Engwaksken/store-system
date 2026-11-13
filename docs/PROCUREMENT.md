# Procurement: process, approval policy and controls (Phase 3)

Code: `app/Services/PurchasingService.php`, `GoodsReceiptService.php`, `PurchaseInvoiceService.php`.

## Flow
```
Purchase requisition (PR) ──submit──▶ Awaiting approval ──Admin/Overall──▶ Approved ──▶ quotes ──▶ select
      │                                     └──reject (reason)──▶ Rejected
      ▼
Purchase order (PO, prices = selected quote) ──▶ Awaiting approval ──▶ Approved ──GRN──▶ Partially received ──GRN──▶ Received
                                                       └─ reject (reason): PR re-opens          └─ close (reason) ─▶ Closed
Approved / received PO ──▶ Supplier invoice ──three-way match──▶ Matched ──▶ payments ──▶ Paid
                                                   └─▶ Match exception ──independent approval──▶ payable
```

## Approval policy (separate from material-issue approval)
| Step | Who may act | Never | Notes |
|---|---|---|---|
| Raise PR | Procurement, Storekeeper, Project Manager (own projects only), Admin | | Project optional (empty = store restock); delivery store required. |
| Approve / reject PR | Owner/Admin, Overall Manager | the requester | Rejection reason mandatory. |
| Record quotes, select supplier | Procurement, Admin | | One quote per supplier per PR. Choosing a quote that is **not the lowest** needs a written reason. |
| Raise PO | Procurement, Admin | | From a PR with quotes, the PO supplier and prices must equal the selected quote. |
| Approve / reject PO | Overall Manager or Admin up to the **owner threshold**; above it, **Owner/Admin only** | the PO's creator | Threshold = Settings → *Purchase orders above this value…* (default 20,000,000 UGX). Approval checks `lock_version` so a stale screen cannot approve. |
| Cancel PO | Procurement, Admin | | Only before anything is received. Re-opens the PR. |
| Close PO | Procurement, Admin | | Partially delivered PO that will not be completed; removes the open commitment. |
| Receive goods (GRN) | Storekeeper assigned to the PO's delivery store, Admin | | Per line ≤ ordered − received. Stock is valued at the PO price. Idempotent. |
| Record supplier invoice | Accountant, Admin | | The same supplier invoice number cannot be entered twice for a supplier. |
| Approve match exception | Owner/Admin, Overall Manager | the person who recorded the invoice | Reason mandatory. |
| Record payment | Accountant, Admin | | Only on matched / exception-approved invoices; never more than the balance. No bank transactions are made. |

## Three-way match rules
For every invoice line (against a PO line):
1. **Price**: invoiced unit price = PO unit price.
2. **Quantity**: PO line invoiced-to-date (including this invoice) ≤ PO line received-to-date (from GRNs).

If any rule fails the invoice is stored as **Match exception**, the reasons are listed on the invoice, and payment is blocked until it is approved by someone other than the recorder.

## Cost treatment
- A GRN is a stock receipt (`stock_receipts.purchase_order_id`), so purchased goods enter the store at the PO price and update the weighted average cost.
- Project cost still comes from **issues** (MVP_SCOPE D9). POs linked to a project are reported as **commitments** (Phase 4) until received, never as cost.
