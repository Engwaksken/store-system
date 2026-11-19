<?php

namespace App\Http\Controllers\Api;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\MaterialIssue;
use App\Models\MaterialRequest;
use App\Models\Store;
use App\Services\IssueService;
use App\Services\RestockService;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Issuing approved requests, issue vouchers and acknowledgment (same rules as the web Issues pages). */
class IssueController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = MaterialIssue::with('project', 'request', 'issuer', 'acknowledgment', 'store')->withCount('lines')->visibleTo($user)
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('issue_no', 'like', $this->like($request->query('q')))
                ->orWhere('recipient_name', 'like', $this->like($request->query('q')))
                ->orWhereHas('request', fn ($r) => $r->where('request_no', 'like', $this->like($request->query('q'))))))
            ->when($request->boolean('unacknowledged'), fn ($q) => $q->whereDoesntHave('acknowledgment'))
            ->latest('issued_at')->latest('id');

        return $this->paginated($request, $query, fn ($i) => PresentOps::issueSummary($i, $user));
    }

    /** Requests with both approvals, waiting for the storekeeper (oldest first). */
    public function queue(Request $request): JsonResponse
    {
        Gate::authorize('issue-stock');
        $query = MaterialRequest::with('project', 'requester')->withCount('lines')
            ->readyToIssueBy($request->user())
            ->oldest('submitted_at');

        return $this->paginated($request, $query, fn ($r) => Present::requestSummary($r));
    }

    public function show(Request $request, MaterialIssue $issue): JsonResponse
    {
        Gate::authorize('view-issue', $issue);

        return response()->json(['data' => PresentOps::issueDetail($issue, $request->user())]);
    }

    /** What the storekeeper may issue now from a store, and what would go to restocking. */
    public function form(Request $request, MaterialRequest $materialRequest, RestockService $restock): JsonResponse
    {
        Gate::authorize('issue-request', $materialRequest);
        $stores = Store::operableBy($request->user())
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $materialRequest->project_id))->orderBy('id')->get();
        $preferred = $stores->firstWhere('project_id', $materialRequest->project_id) ?? $stores->first();
        $storeId = $request->integer('store_id') ?: ($preferred?->id ?? Store::DEFAULT_ID);
        $materialRequest->load(['project', 'requester', 'lines.item.unit', 'lines.item.balances' => fn ($q) => $q->where('store_id', $storeId)]);

        return response()->json(['data' => [
            'request' => Present::requestSummary($materialRequest),
            'stores' => $stores->map(fn ($s) => PresentOps::store($s))->values(),
            'store_id' => $storeId,
            'recipient_name' => $materialRequest->requester?->name,
            'lines' => $materialRequest->lines->map(function ($l) use ($storeId) {
                $out = $l->outstanding();
                $onHand = Decimal::of($l->item->onHand($storeId), 3);

                return [
                    'id' => $l->id, 'item' => ['id' => $l->item->id, 'sku' => $l->item->sku, 'name' => $l->item->name, 'unit' => $l->item->unit?->abbreviation],
                    'final_approved_qty' => Present::qty($l->final_approved_qty), 'issued_qty' => Present::qty($l->issued_qty),
                    'outstanding' => Present::qty($out), 'on_hand' => Present::qty($onHand),
                    'max_issue' => Present::qty(Decimal::cmp($onHand, $out) < 0 ? $onHand : $out),
                ];
            })->values(),
            'shortfall' => collect($restock->shortfall($materialRequest, $storeId))->map(fn ($s) => [
                'line_id' => $s[0]->id, 'item' => ['id' => $s[0]->item->id, 'name' => $s[0]->item->name, 'unit' => $s[0]->item->unit?->abbreviation],
                'short_qty' => Present::qty($s[1]), 'on_hand' => Present::qty($s[2]), 'restock_qty' => Present::qty($s[3]),
            ])->values(),
            'open_restock' => RestockService::available() ? $materialRequest->restockRequisitions()->whereIn('status', RestockService::OPEN)->get(['id', 'pr_no', 'status'])
                ->map(fn ($pr) => ['id' => $pr->id, 'pr_no' => $pr->pr_no])->values() : [],
        ]]);
    }

    public function store(Request $request, MaterialRequest $materialRequest, IssueService $service): JsonResponse
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
            (int) ($data['store_id'] ?? Store::DEFAULT_ID), $data['idempotency_key'] ?? null);
        $restock = $issue->relationLoaded('restock') ? $issue->getRelation('restock') : null;

        return response()->json([
            'data' => PresentOps::issueDetail($issue->fresh(), $request->user()),
            'restock' => $restock ? ['id' => $restock->id, 'pr_no' => $restock->pr_no] : null,
            'replayed' => ! $issue->wasRecentlyCreated,
        ], $issue->wasRecentlyCreated ? 201 : 200);
    }

    public function restock(Request $request, MaterialRequest $materialRequest, RestockService $restock): JsonResponse
    {
        Gate::authorize('issue-request', $materialRequest);
        $data = $request->validate(['store_id' => 'nullable|integer|exists:stores,id']);
        $store = Store::assertOperable($request->user(), (int) ($data['store_id'] ?? Store::DEFAULT_ID));
        $pr = $restock->requestFor($request->user(), $materialRequest, $store->id);

        return response()->json([
            'data' => $pr ? ['id' => $pr->id, 'pr_no' => $pr->pr_no] : null,
            'message' => $pr ? "Restock requisition {$pr->pr_no} sent to the Overall Manager for approval."
                : 'Nothing to restock: the store has enough, or the shortfall is already on an open restock requisition.',
        ], $pr ? 201 : 200);
    }

    public function acknowledge(Request $request, MaterialIssue $issue, IssueService $service): JsonResponse
    {
        Gate::authorize('acknowledge-issue', $issue);
        $data = $request->validate(['recipient_name' => 'nullable|string|max:255', 'remarks' => 'nullable|string|max:1000']);
        $service->acknowledge($request->user(), $issue, $data['recipient_name'] ?? '', $data['remarks'] ?? null);

        return response()->json(['data' => PresentOps::issueDetail($issue->fresh(), $request->user())]);
    }
}
