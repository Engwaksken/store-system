<?php

namespace App\Http\Controllers\Api;

use App\Enums\RequestStatus;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Services\RequestWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MaterialRequestController extends ApiController
{
    public function __construct(private RequestWorkflow $wf)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = MaterialRequest::visibleTo($request->user())->with('project', 'requester')->withCount('lines')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('request_no', 'like', $this->like($request->query('q')))
                ->orWhere('purpose', 'like', $this->like($request->query('q')))))
            ->latest('id');

        return $this->paginated($request, $query, fn ($r) => Present::requestSummary($r));
    }

    /** Requests waiting for this user's decision, oldest first (same rule as the web Approvals page). */
    public function approvals(Request $request): JsonResponse
    {
        Gate::authorize('approve-any');

        return $this->paginatedCollection($request, $this->awaiting($request), fn ($r) => Present::requestSummary($r));
    }

    /** @return \Illuminate\Support\Collection<int, MaterialRequest> */
    public function awaiting(Request $request)
    {
        return MaterialRequest::with('project', 'requester', 'approvals')->withCount('lines')
            ->whereIn('status', [RequestStatus::Submitted->value, RequestStatus::PmApproved->value])
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('request_no', 'like', $this->like($request->query('q')))
                ->orWhere('purpose', 'like', $this->like($request->query('q')))))
            ->oldest('submitted_at')->get()
            ->filter(fn ($r) => Gate::allows('pm-decide', $r) || Gate::allows('overall-decide', $r))->values();
    }

    public function show(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        Gate::authorize('view-request', $materialRequest);

        return response()->json(['data' => Present::requestDetail($materialRequest, $request->user())]);
    }

    public function options(Request $request): JsonResponse
    {
        Gate::authorize('create-request');

        return response()->json(['projects' => Project::requestableBy($request->user())->orderBy('code')->get()->map(fn ($p) => Present::project($p))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create-request');
        $data = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'purpose' => 'required|string|max:1000',
            'needed_on' => 'nullable|date',
            'lines' => 'required|array|min:1|max:100',
            'lines.*.item_id' => 'required|integer|exists:inventory_items,id',
            'lines.*.requested_qty' => 'required|numeric|gt:0',
            'lines.*.notes' => 'nullable|string|max:500',
            'submit' => 'boolean',
        ]);
        $req = $this->wf->createDraft($request->user(), Project::findOrFail($data['project_id']), $data['purpose'], $data['needed_on'] ?? null, $data['lines']);
        if ($request->boolean('submit')) {
            $req = $this->wf->submit($request->user(), $req);
        }

        return response()->json(['data' => Present::requestDetail($req->fresh(), $request->user())], 201);
    }

    /** Decides at whichever step this user may act (PM or overall). */
    public function decide(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        Gate::authorize('view-request', $materialRequest);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => 'nullable|required_if:decision,rejected|string|max:1000',
            'qty' => 'array',
            'qty.*' => 'nullable|numeric|min:0',
            'version' => 'nullable|integer',
        ]);
        $user = $request->user();
        $version = isset($data['version']) ? (int) $data['version'] : null;
        $args = [$user, $materialRequest, $data['decision'], $data['reason'] ?? null, $data['qty'] ?? [], $version];
        match (true) {
            Gate::allows('pm-decide', $materialRequest) => $this->wf->pmDecide(...$args),
            Gate::allows('overall-decide', $materialRequest) => $this->wf->overallDecide(...$args),
            default => throw new \App\Exceptions\WorkflowException("Request {$materialRequest->request_no} is not waiting for your decision."),
        };

        return response()->json(['data' => Present::requestDetail($materialRequest->fresh(), $user)]);
    }

    public function cancel(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        Gate::authorize('view-request', $materialRequest);
        $this->wf->cancel($request->user(), $materialRequest);

        return response()->json(['data' => Present::requestDetail($materialRequest->fresh(), $request->user())]);
    }
}
