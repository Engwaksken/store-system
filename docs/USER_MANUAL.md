# Quick User Manual

Sign in with your e-mail and password. On first sign-in you must set your own password. If you forget it, use **Forgot password?** on the sign-in page.

The left menu shows only what your role can use. Every action is recorded in the activity log.

You get an e-mail when something needs your decision or your request changes: submitted (to the PM), PM-approved (to the Overall Manager), fully approved (to the storekeeper and requester), rejected, and issued. Each e-mail has a button that opens the request.

On request and receipt forms, use **+ Add line** if you need more rows.

## Everyone: how materials leave the store
**Staff → Project Manager → Overall Manager → Storekeeper → you confirm receipt.**
Stock is only deducted when the storekeeper issues. Approval reserves nothing.

## Staff / site personnel
1. **New request**: choose your project (only projects you are assigned to appear), describe the purpose, and add items and quantities. Click **Submit for approval**, or **Save draft** to finish later.
2. Follow progress under **Requests**. The badge shows who it is waiting on.
3. If a request is **rejected**, read the reason, click **Revise & resubmit**, correct it and submit again. It becomes a new version and must be approved again.
4. When materials are issued, open the voucher and click **Confirm materials received**. Note any shortage in *Remarks*.

## Project Manager
- **Approvals** lists requests on *your* projects that are waiting for you.
- Open a request. You may **reduce** quantities (never increase them), then **Approve** or **Reject**. Rejecting requires a reason.
- You cannot approve a request you raised yourself. The Owner/Admin reviews those.
- Project pages show budget, materials issued and cash expenses for projects you manage.

## Overall Manager
- **Approvals** lists requests that the PM has approved.
- Give the final decision. You may reduce quantities further (never above the PM's figure).
- You cannot decide on a request you approved at PM level.
- **Stock counts** submitted by storekeepers wait for your approval. Check the reasons for each difference, then approve (posts the differences to stock) or reject with a comment.
- You can create and edit projects and assign staff, and view all reports and the activity log.

## Storekeeper
- **Issues & vouchers → Ready to issue** lists fully approved requests.
- Click **Issue**. Each line is capped at the smaller of *outstanding approved quantity* and *on-hand stock*, and you can issue less (a partial issue). Enter the name of the person collecting, then confirm.
- **Print voucher** and get it signed. Any remainder stays outstanding until you issue it later.
- **Receipts → Purchase receipt** records deliveries from suppliers (quantity and unit cost).
- **Opening balances** is used once per item at go-live. For many items, click *Download the template*, fill in qty and unit_cost in Excel, save as CSV and upload it. If any row is wrong, nothing is imported and the errors are listed. **Adjustment** is for counted differences or damage, and needs a reason. Stock can never go below zero.
- Rows highlighted on *Stock & items* are at or below the minimum level, so reorder those.

### Several stores (central + site stores)
- You can only receive, issue, transfer, count or adjust in the stores you are assigned to (ask the admin). Every form has a **Store** selector; on-hand figures shown are for that store.
- **Transfers → New transfer**: pick the source store, the destination and the quantities, then dispatch. The stock leaves your store immediately and shows *In transit*.
- At the destination, a **different** storekeeper opens the transfer (the *Transfers* menu shows a count of transfers waiting for you), counts what arrived and confirms. If less arrived, type the reason; the difference is written off. The dispatcher can cancel a transfer only while it is still in transit.
- **Return to store**: open the issue voucher and click *Return to store* when unused material comes back. You cannot return more than is still out on the voucher. The project is credited at the voucher cost.
- **Stock counts → New count** creates a count sheet with the book quantities frozen. Enter what you physically count (0 if none), give a reason for every difference, save, and **Submit for approval**. Nothing changes in stock until the Owner/Admin or Overall Manager approves; then each difference is posted as an adjustment.

## Accountant
- **Expenses → Record expense** is for cash paid directly for a project: labour, transport, hire, direct site purchases. Attach the receipt photo or PDF.
- **Do not** record materials bought into the store as an expense. They are a stock receipt, and become project cost when issued.
- Reports: *Cash expenses by project*, *Materials issued by project*, *Project budget vs cost*. Each can be printed or exported to CSV.

## Owner / Admin
- **Users**: add people, set role, deactivate leavers, or reset a password (the user must then change it).
- **Settings**: company details and logo shown on vouchers, and expense categories.
- **Stores**: add site stores (linked to their project) and tick which storekeepers may operate each store.
- **Activity log**: who did what and when.
- The dashboard shows work waiting, low stock, and each project's budget against cost.

## Reports (print or CSV)
| Report | Shows |
|---|---|
| Stock summary | On hand, minimum level, average cost and value per item |
| Stock movement ledger | Every receipt, issue and adjustment with running balance |
| Pending approvals & issues | Who each request is waiting on and for how many days |
| Materials issued by project | Quantity and value consumed per project and item |
| Cash expenses by project | Totals by expense category |
| Project budget vs cost | Budget, materials issued, cash expenses, total, remaining |
