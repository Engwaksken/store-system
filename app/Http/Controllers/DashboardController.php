<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\ExpenseClaim;
use App\Models\InventoryItem;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use App\Models\MaterialIssue;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Services\ProjectCostService;
use App\Services\RequestWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ProjectCostService $costs, RequestWorkflow $wf)
    {
        $user = $request->user();

        $projects = Project::visibleTo($user)->whereIn('status', ['planned', 'active'])->orderBy('code')->get();
        $lowStock = InventoryItem::with('balances', 'unit')->where('is_active', true)->get()->filter->isLowStock()->values();

        // "My queue": work waiting for this user specifically.
        $awaitingMe = MaterialRequest::with('project', 'requester', 'approvals')
            ->whereIn('status', [RequestStatus::Submitted->value, RequestStatus::PmApproved->value])
            ->latest('submitted_at')->limit(200)->get()
            ->filter(fn ($r) => Gate::allows('pm-decide', $r) || Gate::allows('overall-decide', $r))->values();

        $readyToIssue = $user->role === Role::Storekeeper
            ? MaterialRequest::with('project')->whereIn('status', [RequestStatus::OverallApproved->value, RequestStatus::PartiallyIssued->value])->oldest('submitted_at')->get()
            : collect();

        $myRequests = MaterialRequest::with('project')->where('requester_id', $user->id)->latest()->limit(8)->get();

        $statusCounts = MaterialRequest::visibleTo($user)->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        // Cross-module "waiting on me" counts (each gated exactly like the action itself).
        $queues = collect([
            ['Purchase requisitions to approve', route('prs.index', ['status' => 'submitted']),
                Gate::allows('approve-any') ? PurchaseRequisition::where('status', 'submitted')->where('requested_by', '!=', $user->id)->get()->filter(fn ($p) => Gate::allows('decide-pr', $p))->count() : 0],
            ['Purchase orders to approve', route('pos.index', ['status' => 'pending_approval']),
                $user->hasRole(Role::Admin, Role::OverallManager) ? PurchaseOrder::where('status', 'pending_approval')->get()->filter(fn ($p) => Gate::allows('decide-po', $p))->count() : 0],
            ['Expense claims to approve', route('claims.index', ['status' => 'submitted']),
                $user->hasRole(Role::Admin, Role::OverallManager, Role::ProjectManager) ? ExpenseClaim::with('project')->where('status', 'submitted')->get()->filter(fn ($c) => Gate::allows('decide-claim', $c))->count() : 0],
            ['Claims to pay', route('claims.index', ['status' => 'approved']), Gate::allows('record-expense') ? ExpenseClaim::where('status', 'approved')->count() : 0],
            ['Stock counts to approve', route('counts.index'),
                $user->hasRole(Role::Admin, Role::OverallManager) ? StockCount::where('status', 'submitted')->where('counted_by', '!=', $user->id)->count() : 0],
            ['Invoice match exceptions', route('invoices.index', ['match_status' => 'exception']),
                $user->hasRole(Role::Admin, Role::OverallManager) ? PurchaseInvoice::where('match_status', 'exception')->where('recorded_by', '!=', $user->id)->count() : 0],
            ['Transfers to receive', route('transfers.index', ['status' => 'in_transit']),
                $user->hasRole(Role::Admin, Role::Storekeeper) ? StockTransfer::where('status', 'in_transit')->where('dispatched_by', '!=', $user->id)->whereIn('to_store_id', Store::operableBy($user)->select('id'))->count() : 0],
            ['Purchase orders to receive', route('pos.index', ['status' => 'approved']),
                $user->hasRole(Role::Admin, Role::Storekeeper) ? PurchaseOrder::whereIn('status', PurchaseOrder::RECEIVABLE)->whereIn('store_id', Store::operableBy($user)->select('id'))->count() : 0],
        ])->filter(fn ($q) => $q[2] > 0)->values();

        $finance = null;
        if (Gate::allows('view-invoices')) {
            $openPo = DB::table('purchase_order_lines')->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
                ->whereIn('purchase_orders.status', PurchaseOrder::RECEIVABLE)
                ->selectRaw('SUM((purchase_order_lines.qty - purchase_order_lines.received_qty) * purchase_order_lines.unit_price) as v')->value('v');
            $unpaid = PurchaseInvoice::where('payment_status', '!=', 'paid')->selectRaw('SUM(total - amount_paid) as v, COUNT(*) as n')->first();
            $overdue = PurchaseInvoice::where('payment_status', '!=', 'paid')->whereDate('due_date', '<', now())->selectRaw('SUM(total - amount_paid) as v, COUNT(*) as n')->first();
            $finance = (object) ['openPo' => (string) ($openPo ?? 0), 'unpaid' => (string) ($unpaid->v ?? 0), 'unpaidCount' => (int) $unpaid->n,
                'overdue' => (string) ($overdue->v ?? 0), 'overdueCount' => (int) $overdue->n];
        }
        $storeValues = Gate::allows('view-stock-value')
            ? DB::table('inventory_balances')->join('inventory_items', 'inventory_items.id', '=', 'inventory_balances.item_id')
                ->join('stores', 'stores.id', '=', 'inventory_balances.store_id')->where('inventory_balances.on_hand', '>', 0)
                ->groupBy('stores.id', 'stores.code', 'stores.name')->orderBy('stores.id')
                ->selectRaw('stores.id, stores.code, stores.name, SUM(inventory_balances.on_hand * inventory_items.avg_cost) as value')->get()
            : collect();

        return view('dashboard', [
            'queues' => $queues,
            'finance' => $finance,
            'storeValues' => $storeValues,
            'projects' => $projects,
            'costRows' => Gate::allows('view-finance-reports') ? $costs->summary($projects->filter(fn ($p) => Gate::allows('view-project-finance', $p))) : collect(),
            'lowStock' => $lowStock,
            'awaitingMe' => $awaitingMe,
            'readyToIssue' => $readyToIssue,
            'myRequests' => $myRequests,
            'statusCounts' => $statusCounts,
            'recentIssues' => Gate::allows('view-stock-ledger') ? MaterialIssue::with('project', 'request')->latest('issued_at')->limit(6)->get() : collect(),
        ]);
    }
}
