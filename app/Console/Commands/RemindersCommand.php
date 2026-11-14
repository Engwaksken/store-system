<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\ExpenseClaim;
use App\Models\MaterialRequest;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\ExpenseClaimService;
use App\Services\WorkflowNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Daily reminders and escalations for anything waiting on a person.
 * Older than `cms.reminder_days`: remind whoever must act. Older than `cms.escalation_days`: also tell the Owner/Admin.
 */
class RemindersCommand extends Command
{
    protected $signature = 'cms:reminders';

    protected $description = 'Send reminders for pending approvals and work, escalating overdue items to the Owner/Admin';

    private int $sent = 0;

    public function handle(WorkflowNotifier $n): int
    {
        $remind = now()->subDays((int) config('cms.reminder_days', 2));
        $escalate = now()->subDays((int) config('cms.escalation_days', 5));
        $active = fn () => User::where('is_active', true)->get();
        $users = $active();

        foreach (MaterialRequest::with('project', 'requester')->whereIn('status', [RequestStatus::Submitted->value, RequestStatus::PmApproved->value])->where('submitted_at', '<=', $remind)->get() as $r) {
            $who = $users->filter(fn ($u) => Gate::forUser($u)->allows('pm-decide', $r) || Gate::forUser($u)->allows('overall-decide', $r));
            // The Owner/Admin may decide any request, but is chased only when no PM / Overall Manager can (escalation still reaches them).
            $regular = $who->filter(fn ($u) => $u->role !== Role::Admin);
            $who = $regular->isNotEmpty() ? $regular : $who;
            $this->chase($n, $who, $r->submitted_at, $escalate, "material request {$r->request_no} ({$r->project->code}) - {$r->status->label()}", route('requests.show', $r));
        }
        foreach (MaterialRequest::with('project')->whereIn('status', [RequestStatus::OverallApproved->value, RequestStatus::PartiallyIssued->value])->where('updated_at', '<=', $remind)->get() as $r) {
            $this->chase($n, $users->where('role', Role::Storekeeper), $r->updated_at, $escalate, "approved request {$r->request_no} ({$r->project->code}) not fully issued", route('requests.show', $r));
        }
        foreach (PurchaseRequisition::where('status', 'submitted')->where('submitted_at', '<=', $remind)->get() as $pr) {
            $this->chase($n, $users->filter(fn ($u) => Gate::forUser($u)->allows('decide-pr', $pr)), $pr->submitted_at, $escalate, "purchase requisition {$pr->pr_no} awaiting approval", route('prs.show', $pr));
        }
        foreach (PurchaseOrder::where('status', 'pending_approval')->where('created_at', '<=', $remind)->get() as $po) {
            $this->chase($n, $users->filter(fn ($u) => Gate::forUser($u)->allows('decide-po', $po)), $po->created_at, $escalate, "purchase order {$po->po_no} awaiting approval", route('pos.show', $po));
        }
        foreach (PurchaseInvoice::where('match_status', 'exception')->where('created_at', '<=', $remind)->get() as $inv) {
            $this->chase($n, $users->filter(fn ($u) => Gate::forUser($u)->allows('approve-invoice-exception', $inv)), $inv->created_at, $escalate, "invoice match exception {$inv->pi_no}", route('invoices.show', $inv));
        }
        foreach (ExpenseClaim::with('project')->where('status', 'submitted')->where('created_at', '<=', $remind)->get() as $c) {
            $who = $users->filter(fn ($u) => app(ExpenseClaimService::class)->canDecide($u, $c));
            $this->chase($n, $who, $c->created_at, $escalate, "expense claim {$c->claim_no} awaiting approval", route('claims.show', $c));
        }
        foreach (ExpenseClaim::where('status', 'approved')->where('decided_at', '<=', $remind)->get() as $c) {
            $this->chase($n, $users->where('role', Role::Accountant), $c->decided_at, $escalate, "approved claim {$c->claim_no} not yet paid", route('claims.show', $c));
        }
        foreach (StockCount::with('store')->where('status', 'submitted')->where('submitted_at', '<=', $remind)->get() as $sc) {
            $this->chase($n, $users->filter(fn ($u) => Gate::forUser($u)->allows('decide-count', $sc)), $sc->submitted_at, $escalate, "stock count {$sc->count_no} awaiting approval", route('counts.show', $sc));
        }
        foreach (StockTransfer::with('toStore.users')->where('status', 'in_transit')->where('dispatched_at', '<=', $remind)->get() as $t) {
            $this->chase($n, $t->toStore->users->where('id', '!=', $t->dispatched_by), $t->dispatched_at, $escalate, "transfer {$t->transfer_no} still in transit", route('transfers.show', $t));
        }

        $this->info("Reminders sent: {$this->sent}.");

        return self::SUCCESS;
    }

    private function chase(WorkflowNotifier $n, Collection $who, Carbon $since, Carbon $escalate, string $what, string $url): void
    {
        $days = (int) $since->diffInDays(now(), true);
        if ($who->isNotEmpty()) {
            $n->send($who, "Reminder: {$what}", "This has been waiting {$days} day(s) for your action: {$what}.", $url, 'Open', 'warn');
            $this->sent += $who->count();
        }
        if ($since->lte($escalate)) {
            $admins = $n->role(Role::Admin);
            $n->send($admins, "Escalation: {$what}", "Overdue {$days} day(s): {$what}.".($who->isEmpty() ? ' Nobody is currently able to act on it.' : ' Waiting on: '.$who->pluck('name')->implode(', ').'.'), $url, 'Open', 'bad');
            $this->sent += $admins->count();
        }
    }
}
