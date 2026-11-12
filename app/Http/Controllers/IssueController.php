<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\AppSetting;
use App\Models\MaterialIssue;
use App\Models\MaterialRequest;
use App\Services\IssueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class IssueController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $issues = MaterialIssue::with('project', 'request', 'issuer', 'acknowledgment')
            ->when(! $user->hasRole(Role::Admin, Role::OverallManager, Role::Storekeeper, Role::Accountant),
                fn ($q) => $q->whereHas('request', fn ($r) => $r->visibleTo($user)))
            ->latest('issued_at')->paginate(25);

        $queue = Gate::allows('issue-stock')
            ? MaterialRequest::with('project', 'requester')->whereIn('status', [RequestStatus::OverallApproved->value, RequestStatus::PartiallyIssued->value])->oldest('submitted_at')->get()
            : collect();

        return view('issues.index', compact('issues', 'queue'));
    }

    public function create(MaterialRequest $materialRequest)
    {
        Gate::authorize('issue-request', $materialRequest);

        return view('issues.create', ['req' => $materialRequest->load('project', 'requester', 'lines.item.unit', 'lines.item.balance')]);
    }

    public function store(Request $request, MaterialRequest $materialRequest, IssueService $service)
    {
        Gate::authorize('issue-stock');
        $data = $request->validate([
            'qty' => 'required|array',
            'qty.*' => 'nullable|numeric|min:0',
            'recipient_name' => 'required|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);
        $issue = $service->issue($request->user(), $materialRequest, $data['qty'], $data['recipient_name'], $data['notes'] ?? null);

        return redirect()->route('issues.show', $issue)->with('status', "Voucher {$issue->issue_no} created. Print it for the recipient's signature.");
    }

    public function show(MaterialIssue $issue)
    {
        Gate::authorize('view-issue', $issue);

        return view('issues.show', [
            'issue' => $issue->load('project', 'request.requester', 'request.approvals.approver', 'issuer', 'lines.item.unit', 'acknowledgment.user'),
            'company' => AppSetting::get('company_name', config('app.name')),
        ]);
    }

    public function acknowledge(Request $request, MaterialIssue $issue, IssueService $service)
    {
        Gate::authorize('acknowledge-issue', $issue);
        $data = $request->validate(['recipient_name' => 'nullable|string|max:255', 'remarks' => 'nullable|string|max:1000']);
        $service->acknowledge($request->user(), $issue, $data['recipient_name'] ?? '', $data['remarks'] ?? null);

        return back()->with('status', 'Receipt acknowledged.');
    }
}
