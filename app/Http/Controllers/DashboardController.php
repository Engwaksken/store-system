<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\InventoryItem;
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
        $lowStock = InventoryItem::with('balance', 'unit')->where('is_active', true)->get()->filter->isLowStock()->values();

        // "My queue": work waiting for this user specifically.
        $awaitingMe = MaterialRequest::with('project', 'requester')
            ->whereIn('status', [RequestStatus::Submitted->value, RequestStatus::PmApproved->value])
            ->latest('submitted_at')->limit(200)->get()
            ->filter(fn ($r) => Gate::allows('pm-decide', $r) || Gate::allows('overall-decide', $r))->values();

        $readyToIssue = $user->role === Role::Storekeeper
            ? MaterialRequest::with('project')->whereIn('status', [RequestStatus::OverallApproved->value, RequestStatus::PartiallyIssued->value])->oldest('submitted_at')->get()
            : collect();

        $myRequests = MaterialRequest::with('project')->where('requester_id', $user->id)->latest()->limit(8)->get();

        $statusCounts = MaterialRequest::visibleTo($user)->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return view('dashboard', [
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
