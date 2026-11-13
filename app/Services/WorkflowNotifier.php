<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\ExpenseClaim;
use App\Models\MaterialIssue;
use App\Models\MaterialRequest;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\User;
use App\Notifications\WorkflowAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Tells the next person in a chain that something is waiting (in-app bell + e-mail).
 * Sent only after the database transaction commits, and a delivery failure never undoes or blocks a decision.
 */
class WorkflowNotifier
{
    // ---------------------------------------------------------------- material requests

    public function submitted(MaterialRequest $req): void
    {
        $to = $req->project_manager_id && $req->project_manager_id !== $req->requester_id
            ? User::whereKey($req->project_manager_id)->get()
            : $this->role(Role::Admin);
        $this->send($to, "Approval needed: {$req->request_no}", "{$req->requester->name} submitted material request {$req->request_no} for project {$req->project->code}. It is waiting for the Project Manager decision.", route('requests.show', $req), 'Open request');
    }

    public function pmApproved(MaterialRequest $req): void
    {
        $this->send($this->role(Role::OverallManager), "Final approval needed: {$req->request_no}", "Material request {$req->request_no} for project {$req->project->code} was approved by the Project Manager and needs the Overall Manager decision.", route('requests.show', $req), 'Open request');
    }

    public function overallApproved(MaterialRequest $req): void
    {
        $this->send($this->role(Role::Storekeeper), "Ready to issue: {$req->request_no}", "Material request {$req->request_no} for project {$req->project->code} is fully approved and ready to issue from the store.", route('requests.show', $req), 'Open request');
        $this->send(User::whereKey($req->requester_id)->get(), "Approved: {$req->request_no}", "Your material request {$req->request_no} is fully approved. The storekeeper will issue the materials.", route('requests.show', $req), 'Open request', 'ok');
    }

    public function rejected(MaterialRequest $req, string $step, string $reason): void
    {
        $who = $step === 'pm' ? 'the Project Manager' : 'the Overall Manager';
        $this->send(User::whereKey($req->requester_id)->get(), "Rejected: {$req->request_no}", "Your material request {$req->request_no} was rejected by {$who}. Reason: {$reason}. You can revise and resubmit it.", route('requests.show', $req), 'Open request', 'bad');
    }

    public function issued(MaterialIssue $issue): void
    {
        $req = $issue->request;
        $this->send(User::whereKey($req->requester_id)->get(), "Materials issued: {$issue->issue_no}", "Materials for your request {$req->request_no} were issued on voucher {$issue->issue_no} to {$issue->recipient_name}. Please confirm receipt.",
            route('issues.show', $issue), 'Open voucher', 'ok');
    }

    // ---------------------------------------------------------------- stores, purchasing, claims

    public function transferDispatched(StockTransfer $t): void
    {
        $to = $t->toStore->users()->where('users.id', '!=', $t->dispatched_by)->get();
        $this->send($to, "Incoming transfer: {$t->transfer_no}", "Transfer {$t->transfer_no} from {$t->fromStore->name} is on its way to {$t->toStore->name}. Confirm the quantities when it arrives.", route('transfers.show', $t), 'Open transfer');
    }

    public function countSubmitted(StockCount $c): void
    {
        $to = User::whereIn('role', [Role::Admin->value, Role::OverallManager->value])->where('id', '!=', $c->counted_by)->get();
        $this->send($to, "Stock count to approve: {$c->count_no}", "{$c->counter->name} submitted stock count {$c->count_no} for {$c->store->name}. Approving posts the variances to stock.", route('counts.show', $c), 'Review count');
    }

    public function requisitionSubmitted(PurchaseRequisition $pr): void
    {
        $to = User::whereIn('role', [Role::Admin->value, Role::OverallManager->value])->where('id', '!=', $pr->requested_by)->get();
        $this->send($to, "Purchase requisition to approve: {$pr->pr_no}", "{$pr->requester->name} submitted purchase requisition {$pr->pr_no} ({$pr->purpose}).", route('prs.show', $pr), 'Review requisition');
    }

    public function requisitionDecided(PurchaseRequisition $pr): void
    {
        $this->send(User::whereKey($pr->requested_by)->get(), "Purchase requisition {$pr->status}: {$pr->pr_no}",
            "Your purchase requisition {$pr->pr_no} was {$pr->status}".($pr->decision_reason ? ": {$pr->decision_reason}" : '.'), route('prs.show', $pr), 'Open requisition', $pr->status === 'approved' ? 'ok' : 'bad');
        if ($pr->status === 'approved') {
            $this->send($this->role(Role::Procurement), "Ready to quote / order: {$pr->pr_no}", "Purchase requisition {$pr->pr_no} is approved. Record quotes and raise the purchase order.", route('prs.show', $pr), 'Open requisition');
        }
    }

    public function orderPending(PurchaseOrder $po): void
    {
        $to = User::whereIn('role', [Role::Admin->value, Role::OverallManager->value])->where('is_active', true)->get()
            ->filter(fn ($u) => Gate::forUser($u)->allows('decide-po', $po));
        $this->send($to, "Purchase order to approve: {$po->po_no}", "Purchase order {$po->po_no} to {$po->supplier->name} for ".ugx($po->total).' UGX is waiting for approval.', route('pos.show', $po), 'Review PO');
    }

    public function orderApproved(PurchaseOrder $po): void
    {
        $this->send(User::whereKey($po->created_by)->get(), "PO {$po->status}: {$po->po_no}", "Purchase order {$po->po_no} was {$po->status}.", route('pos.show', $po), 'Open PO', $po->status === 'approved' ? 'ok' : 'bad');
        if ($po->status === 'approved') {
            $this->send($po->store->users()->get(), "Expect delivery: {$po->po_no}", "Purchase order {$po->po_no} from {$po->supplier->name} is approved for delivery to {$po->store->name}. Post a GRN when goods arrive.", route('pos.show', $po), 'Open PO');
        }
    }

    public function invoiceException(PurchaseInvoice $inv): void
    {
        $to = User::whereIn('role', [Role::Admin->value, Role::OverallManager->value])->where('id', '!=', $inv->recorded_by)->get();
        $this->send($to, "Invoice match exception: {$inv->pi_no}", "Supplier invoice {$inv->supplier_invoice_no} from {$inv->supplier->name} does not match the PO or goods received. Payment is blocked until reviewed.", route('invoices.show', $inv), 'Review invoice', 'bad');
    }

    public function claimSubmitted(ExpenseClaim $c): void
    {
        $to = User::whereIn('role', [Role::Admin->value, Role::OverallManager->value, Role::ProjectManager->value])->where('is_active', true)->get()
            ->filter(fn ($u) => app(ExpenseClaimService::class)->canDecide($u, $c) && ($u->role === Role::ProjectManager || $c->project->manager_id === null || $c->project->manager_id === $c->claimant_id));
        $this->send($to, "Expense claim to approve: {$c->claim_no}", "{$c->claimant->name} claimed ".ugx($c->amount)." UGX on {$c->project->code}: {$c->description}", route('claims.show', $c), 'Review claim');
    }

    public function claimDecided(ExpenseClaim $c): void
    {
        $this->send(User::whereKey($c->claimant_id)->get(), "Claim {$c->status}: {$c->claim_no}", "Your expense claim {$c->claim_no} was {$c->status}".($c->decision_reason ? ": {$c->decision_reason}" : '.'), route('claims.show', $c), 'Open claim', $c->status === 'approved' ? 'ok' : 'bad');
        if ($c->status === 'approved') {
            $this->send($this->role(Role::Accountant), "Claim to pay: {$c->claim_no}", "Expense claim {$c->claim_no} ({$c->claimant->name}, ".ugx($c->amount).' UGX) is approved and waiting for payment.', route('claims.show', $c), 'Open claim');
        }
    }

    // ---------------------------------------------------------------- generic

    /** @param Collection<int, User> $users */
    public function send(Collection $users, string $subject, string $body, string $url, string $action = 'Open', string $kind = 'info'): void
    {
        $users = $users->where('is_active', true)->unique('id')->values();
        if ($users->isEmpty()) {
            return;
        }
        DB::afterCommit(function () use ($users, $subject, $body, $url, $action, $kind) {
            try {
                Notification::send($users, new WorkflowAlert($subject, $body, $url, $action, $kind));
            } catch (Throwable $e) {
                report($e); // the decision is already saved; never fail the user's action because mail is down
            }
        });
    }

    public function role(Role ...$roles): Collection
    {
        return User::whereIn('role', array_map(fn ($r) => $r->value, $roles))->where('is_active', true)->get();
    }
}
