# Quick User Manual

Sign in with your e-mail and password. On first sign-in you must set your own password. If you forget it, use **Forgot password?** on the sign-in page.

The left menu shows only what your role can use. Every action is recorded in the activity log.

You get an e-mail when something needs your decision or your request changes: submitted (to the PM), PM-approved (to the Overall Manager), fully approved (to the storekeeper and requester), rejected, and issued. Each e-mail has a button that opens the request.

On request and receipt forms, use **+ Add line** if you need more rows.

## Everyone: how materials leave the store
**Staff → Project Manager → Overall Manager → Storekeeper → you confirm receipt.**
Stock is only deducted when the storekeeper issues. Approval reserves nothing.

## Staff / site personnel
- **Expense claims → New claim**: when you pay for something on site, claim it with the receipt photo. Your PM approves it and the accountant pays you.
1. **New request**: choose your project (only projects you are assigned to appear), describe the purpose, and add items and quantities. Click **Submit for approval**, or **Save draft** to finish later.
2. Follow progress under **Requests**. The badge shows who it is waiting on.
3. If a request is **rejected**, read the reason, click **Revise & resubmit**, correct it and submit again. It becomes a new version and must be approved again.
4. When materials are issued, open the voucher and click **Confirm materials received**. Note any shortage in *Remarks*.

## Project Manager
- **Budget, BOQ & milestones** (button on your project): enter the budget per cost code, the BOQ lines and milestones. The table shows, per cost code, the budget, actual cost, committed cost (open purchase orders and approved claims) and the variance. Amber means 90 % or more is used or committed; red means over budget.
- When staff raise a material request they can link each line to a BOQ line, so consumption is reported per BOQ line.
- **Expense claims** from your team wait for your approval. You cannot approve your own claim; it goes to the Overall Manager.
- **Approvals** lists requests on *your* projects that are waiting for you.
- Open a request. You may **reduce** quantities (never increase them), then **Approve** or **Reject**. Rejecting requires a reason.
- You cannot approve a request you raised yourself. The Owner/Admin reviews those.
- Project pages show budget, materials issued and cash expenses for projects you manage.

## Overall Manager
- **Approvals** lists requests that the PM has approved.
- Give the final decision. You may reduce quantities further (never above the PM's figure).
- You cannot decide on a request you approved at PM level.
- **Purchase requisitions / purchase orders** awaiting your decision are counted next to *Purchase requisitions* in the menu. You cannot approve your own requisition or a PO you raised.
- **Supplier invoices** with a *Match exception* need your review: approve with a reason only when the difference is agreed.
- **Stock counts** submitted by storekeepers wait for your approval. Check the reasons for each difference, then approve (posts the differences to stock) or reject with a comment.
- You can create and edit projects and assign staff, and view all reports and the activity log.

## Storekeeper
- **Purchase orders**: when goods arrive for an approved PO delivered to your store, open the PO and click **Receive goods (GRN)**. Enter what actually arrived; you cannot receive more than is still on order. The GRN updates stock at the PO price.
- You can raise **Purchase requisitions** when stock runs low.
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

## Accountant: claims and cost codes
- Approved **Expense claims** are counted next to the menu item. Record the payment on the claim; this creates the project expense once.
- **Cost codes** are maintained by you or the Owner/Admin.

## Procurement Officer
- For project purchase orders, choose the **cost code** so the order shows as a commitment against that budget line.
- **Purchase requisitions** shows requests to buy. Once a requisition is *approved*, record each supplier's quotation on it (one per supplier) and **Select** the winner. If it is not the lowest, type why.
- Click **Raise purchase order**: supplier, prices and quantities come from the selected quote. Saving sends the PO for approval. You cannot approve your own PO; above the owner threshold only the Owner/Admin can.
- **Print PO** gives a branded order with signature blocks for the supplier.
- **Cancel** a PO only before anything is delivered. If a supplier will never deliver the balance, **Close** the PO instead.
- **Suppliers**: keep contacts, TIN and payment terms up to date.

## Accountant
- **Supplier invoices**: open the PO and click *Record supplier invoice*. Enter the invoice exactly as printed and attach the scan. If the price differs from the PO, or it bills more than has been received, it is flagged as a *Match exception*; ask the Overall Manager or Owner to review it. You can record payments only on matched or approved invoices, and never more than the balance.
- **Expenses → Record expense** is for cash paid directly for a project: labour, transport, hire, direct site purchases. Attach the receipt photo or PDF.
- **Do not** record materials bought into the store as an expense. They are a stock receipt, and become project cost when issued.
- Reports: *Cash expenses by project*, *Materials issued by project*, *Project budget vs cost*. Each can be printed or exported to CSV.

## Owner / Admin
- **Users**: add people, set role, deactivate leavers, or reset a password (the user must then change it).
- **Settings**: company details and logo shown on vouchers and PDFs, the purchase-order owner threshold, and expense categories.
- **Imports**: *Stock & items → Import items* (CSV of new catalogue items) and *project → Budget, BOQ & milestones → Import BOQ*. Download the template first; if any row is wrong nothing is imported.
- **Stores**: add site stores (linked to their project) and tick which storekeepers may operate each store.
- **Activity log**: who did what and when.
- The dashboard shows work waiting, low stock, and each project's budget against cost.

## Notifications
- The bell (🔔) at the top shows alerts for you: approvals waiting, goods to receive, claims to pay, rejections, reminders and budget alerts. Click one to open the document; it is then marked read. The same alerts are e-mailed if the company enabled e-mail.
- Anything waiting more than 2 days is reminded every weekday morning; after 5 days the Owner/Admin is told as well.

## Auditor
- You can open every register, report and the activity log, and export them (Excel, PDF, CSV). You cannot change anything.
- **Activity log → Excel** exports the full trail with each row's hash. The system checks the trail every night; ask the Owner/Admin for the latest `cms:verify-audit` result.

## Reports (print, Excel, PDF or CSV)
| Report | Shows |
|---|---|
| Stock summary | On hand, minimum level, average cost and value per item |
| Stock movement ledger | Every receipt, issue and adjustment with running balance |
| Pending approvals & issues | Who each request is waiting on and for how many days |
| Materials issued by project | Quantity and value consumed per project and item |
| Cash expenses by project | Totals by expense category |
| Project budget vs cost | Budget, materials issued, cash expenses, total, remaining |
