<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Models\InventoryItem;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Services\RequestWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MaterialRequestController extends Controller
{
    public function __construct(private RequestWorkflow $wf)
    {
    }

    public function index(Request $request)
    {
        $requests = MaterialRequest::visibleTo($request->user())->with('project', 'requester')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->latest('id')->paginate(25)->withQueryString();

        $counts = MaterialRequest::visibleTo($request->user())->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $n = fn (RequestStatus ...$s) => collect($s)->sum(fn ($x) => (int) ($counts[$x->value] ?? 0));

        return view('requests.index', [
            'stats' => [
                'total' => (int) $counts->sum(),
                'pending' => $n(RequestStatus::Submitted, RequestStatus::PmApproved),
                'ready' => $n(RequestStatus::OverallApproved, RequestStatus::PartiallyIssued),
                'done' => $n(RequestStatus::FullyIssued),
            ],
            'requests' => $requests,
            'projects' => Project::visibleTo($request->user())->orderBy('code')->get(),
        ]);
    }

    /** Requests waiting for the current user's decision. */
    public function approvals(Request $request)
    {
        Gate::authorize('approve-any');
        $pending = MaterialRequest::with('project', 'requester', 'lines', 'approvals')
            ->whereIn('status', [RequestStatus::Submitted->value, RequestStatus::PmApproved->value])
            ->oldest('submitted_at')->get()
            ->filter(fn ($r) => Gate::allows('pm-decide', $r) || Gate::allows('overall-decide', $r))->values();

        $stats = [
            'pending' => $pending->count(),
            'pm' => $pending->where('status', RequestStatus::Submitted)->count(),
            'overall' => $pending->where('status', RequestStatus::PmApproved)->count(),
            'oldest' => $pending->first()?->submitted_at?->diffForHumans(null, true) ?? '-',
        ];

        return view('requests.approvals', compact('pending', 'stats'));
    }

    public function create(Request $request)
    {
        Gate::authorize('create-request');

        return view('requests.form', ['req' => new MaterialRequest(), ...$this->formOptions($request)]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create-request');
        $data = $this->validated($request);
        $project = Project::findOrFail($data['project_id']);
        $req = $this->wf->createDraft($request->user(), $project, $data['purpose'], $data['needed_on'] ?? null, $data['lines']);
        if ($request->input('action') === 'submit') {
            $this->wf->submit($request->user(), $req);

            return redirect()->route('requests.show', $req)->with('status', "Request {$req->request_no} submitted to the Project Manager.");
        }

        return redirect()->route('requests.show', $req)->with('status', "Draft {$req->request_no} saved.");
    }

    public function show(MaterialRequest $materialRequest)
    {
        Gate::authorize('view-request', $materialRequest);
        $materialRequest->load('project', 'requester', 'projectManager', 'lines.item.unit', 'lines.item.balances', 'approvals.approver', 'issues.acknowledgment');

        return view('requests.show', ['req' => $materialRequest, 'wf' => $this->wf]);
    }

    public function edit(Request $request, MaterialRequest $materialRequest)
    {
        Gate::authorize('edit-request', $materialRequest);

        return view('requests.form', ['req' => $materialRequest->load('lines'), ...$this->formOptions($request)]);
    }

    public function update(Request $request, MaterialRequest $materialRequest)
    {
        Gate::authorize('edit-request', $materialRequest);
        $data = $this->validated($request);
        if ((int) $data['project_id'] !== $materialRequest->project_id) {
            return back()->withInput()->withErrors(['project_id' => 'Project cannot be changed on an existing request; cancel it and create a new one.']);
        }
        $this->wf->updateDraft($request->user(), $materialRequest, $data['purpose'], $data['needed_on'] ?? null, $data['lines']);
        if ($request->input('action') === 'submit') {
            $this->wf->submit($request->user(), $materialRequest);

            return redirect()->route('requests.show', $materialRequest)->with('status', 'Request submitted to the Project Manager.');
        }

        return redirect()->route('requests.show', $materialRequest)->with('status', 'Draft saved.');
    }

    public function submit(Request $request, MaterialRequest $materialRequest)
    {
        $this->wf->submit($request->user(), $materialRequest);

        return back()->with('status', 'Request submitted to the Project Manager.');
    }

    public function cancel(Request $request, MaterialRequest $materialRequest)
    {
        $this->wf->cancel($request->user(), $materialRequest);

        return back()->with('status', 'Request cancelled.');
    }

    public function revise(Request $request, MaterialRequest $materialRequest)
    {
        $req = $this->wf->revise($request->user(), $materialRequest);

        return redirect()->route('requests.edit', $req)->with('status', "Request reopened as version {$req->version}. Edit and resubmit.");
    }

    public function pmDecision(Request $request, MaterialRequest $materialRequest)
    {
        // Refuse before validating; the workflow re-checks state and separation of duties under a lock.
        Gate::authorize('approve-any');
        $d = $this->decisionInput($request);
        $this->wf->pmDecide($request->user(), $materialRequest, $d['decision'], $d['reason'] ?? null, $d['qty'] ?? []);

        return redirect()->route('requests.show', $materialRequest)->with('status', 'PM decision recorded.');
    }

    public function overallDecision(Request $request, MaterialRequest $materialRequest)
    {
        // Refuse before validating; the workflow re-checks state and separation of duties under a lock.
        Gate::authorize('approve-any');
        $d = $this->decisionInput($request);
        $this->wf->overallDecide($request->user(), $materialRequest, $d['decision'], $d['reason'] ?? null, $d['qty'] ?? []);

        return redirect()->route('requests.show', $materialRequest)->with('status', 'Overall decision recorded.');
    }

    private function decisionInput(Request $request): array
    {
        return $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => 'nullable|required_if:decision,rejected|string|max:1000',
            'qty' => 'array',
            'qty.*' => 'nullable|numeric|min:0',
        ]);
    }

    private function formOptions(Request $request): array
    {
        return [
            'projects' => $projects = Project::requestableBy($request->user())->with('boqItems')->orderBy('code')->get(),
            'items' => InventoryItem::with('unit', 'balances')->where('is_active', true)->orderBy('name')->get(),
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'project_id' => 'required|exists:projects,id',
            'purpose' => 'required|string|max:1000',
            'needed_on' => 'nullable|date',
            'lines' => 'required|array|min:1',
            'lines.*.item_id' => 'nullable|exists:inventory_items,id',
            'lines.*.requested_qty' => 'nullable|required_with:lines.*.item_id|numeric|gt:0',
            'lines.*.notes' => 'nullable|string|max:500',
            'lines.*.boq_item_id' => 'nullable|integer|exists:boq_items,id',
        ]);
    }
}
