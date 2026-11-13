<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\AppSetting;
use App\Models\MaterialIssue;
use App\Models\MaterialRequest;
use App\Models\Store;
use App\Services\IssueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class IssueController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $issues = MaterialIssue::with('project', 'request', 'issuer', 'acknowledgment')
            ->when(! $user->hasRole(Role::Admin, Role::OverallManager, Role::Storekeeper, Role::Accountant, Role::Auditor),
                fn ($q) => $q->whereHas('request', fn ($r) => $r->visibleTo($user)))
            ->latest('issued_at')->paginate(25);

        $queue = Gate::allows('issue-stock')
            ? MaterialRequest::with('project', 'requester')->whereIn('status', [RequestStatus::OverallApproved->value, RequestStatus::PartiallyIssued->value])->oldest('submitted_at')->get()
            : collect();

        return view('issues.index', compact('issues', 'queue'));
    }

    public function create(Request $request, MaterialRequest $materialRequest)
    {
        Gate::authorize('issue-request', $materialRequest);
        // Stores this storekeeper operates that may issue to this project (site stores serve only their own project).
        $stores = Store::operableBy($request->user())
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $materialRequest->project_id))->orderBy('id')->get();
        $preferred = $stores->firstWhere('project_id', $materialRequest->project_id) ?? $stores->first();
        $storeId = $request->integer('store_id', $preferred?->id ?? Store::DEFAULT_ID);

        return view('issues.create', [
            'req' => $materialRequest->load(['project', 'requester', 'lines.item.unit', 'lines.item.balances' => fn ($q) => $q->where('store_id', $storeId)]),
            'stores' => $stores, 'storeId' => $storeId,
        ]);
    }

    public function store(Request $request, MaterialRequest $materialRequest, IssueService $service)
    {
        Gate::authorize('issue-stock');
        $data = $request->validate([
            'qty' => 'required|array',
            'qty.*' => 'nullable|numeric|min:0',
            'recipient_name' => 'required|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'store_id' => 'nullable|integer|exists:stores,id',
            'idempotency_key' => 'nullable|string|max:64',
        ]);
        $issue = $service->issue($request->user(), $materialRequest, $data['qty'], $data['recipient_name'], $data['notes'] ?? null,
            (int) ($data['store_id'] ?? \App\Models\Store::DEFAULT_ID), $data['idempotency_key'] ?? null);

        return redirect()->route('issues.show', $issue)->with('status', $issue->wasRecentlyCreated
            ? "Voucher {$issue->issue_no} created. Print it for the recipient's signature."
            : "Voucher {$issue->issue_no} was already created; nothing was issued twice.");
    }

    public function show(MaterialIssue $issue)
    {
        Gate::authorize('view-issue', $issue);

        return view('issues.show', [
            'issue' => $issue->load('project', 'store', 'request.requester', 'request.approvals.approver', 'issuer', 'lines.item.unit', 'lines.returnLines', 'acknowledgment.user', 'returns'),
            'company' => AppSetting::get('company_name', config('app.name')),
        ]);
    }

    public function pdf(MaterialIssue $issue)
    {
        Gate::authorize('view-issue', $issue);

        return \App\Support\Pdf::download('pdf.issue', [
            'issue' => $issue->load('project', 'store', 'request.requester', 'request.approvals.approver', 'issuer', 'lines.item.unit', 'acknowledgment'),
            'showValue' => Gate::allows('view-stock-value'),
        ], $issue->issue_no.'.pdf');
    }

    public function acknowledge(Request $request, MaterialIssue $issue, IssueService $service)
    {
        Gate::authorize('acknowledge-issue', $issue);
        $data = $request->validate(['recipient_name' => 'nullable|string|max:255', 'remarks' => 'nullable|string|max:1000']);
        $service->acknowledge($request->user(), $issue, $data['recipient_name'] ?? '', $data['remarks'] ?? null);

        return back()->with('status', 'Receipt acknowledged.');
    }
}
