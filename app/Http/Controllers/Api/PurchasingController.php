<?php

namespace App\Http\Controllers\Api;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Services\PurchasingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Purchase requisitions and orders on the mobile API: view, submit and approve / reject (quotes and PO creation stay on the web). */
class PurchasingController extends ApiController
{
    public function __construct(private PurchasingService $service)
    {
    }

    public function requisitions(Request $request): JsonResponse
    {
        Gate::authorize('view-purchasing');
        $query = PurchaseRequisition::visibleTo($request->user())->with('project', 'store', 'requester', 'materialRequest')->withCount('lines')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->boolean('restock'), fn ($q) => $q->whereNotNull('material_request_id'))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('pr_no', 'like', $this->like($request->query('q')))
                ->orWhere('purpose', 'like', $this->like($request->query('q')))))
            ->latest('id');

        return $this->paginated($request, $query, fn ($pr) => PresentOps::prSummary($pr));
    }

    public function requisition(Request $request, PurchaseRequisition $pr): JsonResponse
    {
        Gate::authorize('view-pr', $pr);

        return response()->json(['data' => PresentOps::prDetail($pr, $request->user())]);
    }

    public function submitRequisition(Request $request, PurchaseRequisition $pr): JsonResponse
    {
        Gate::authorize('view-pr', $pr);
        $pr = $this->service->submitRequisition($request->user(), $pr);

        return response()->json(['data' => PresentOps::prDetail($pr->fresh(), $request->user())]);
    }

    public function decideRequisition(Request $request, PurchaseRequisition $pr): JsonResponse
    {
        Gate::authorize('decide-pr', $pr);
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'reason' => 'nullable|required_if:decision,rejected|string|max:1000']);
        $pr = $this->service->decideRequisition($request->user(), $pr, $data['decision'], $data['reason'] ?? null);

        return response()->json(['data' => PresentOps::prDetail($pr->fresh(), $request->user())]);
    }

    public function orders(Request $request): JsonResponse
    {
        Gate::authorize('view-purchasing');
        $query = PurchaseOrder::visibleTo($request->user())->with('supplier', 'project', 'store', 'creator')->withCount('lines')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('po_no', 'like', $this->like($request->query('q')))
                ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $this->like($request->query('q'))))))
            ->latest('id');

        return $this->paginated($request, $query, fn ($po) => PresentOps::poSummary($po));
    }

    public function order(Request $request, PurchaseOrder $po): JsonResponse
    {
        Gate::authorize('view-po', $po);

        return response()->json(['data' => PresentOps::poDetail($po, $request->user())]);
    }

    public function decideOrder(Request $request, PurchaseOrder $po): JsonResponse
    {
        Gate::authorize('decide-po', $po);
        $data = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'reason' => 'nullable|required_if:decision,rejected|string|max:1000',
            'lock_version' => 'required|integer',
        ]);
        $po = $this->service->decideOrder($request->user(), $po, $data['decision'], $data['reason'] ?? null, (int) $data['lock_version']);

        return response()->json(['data' => PresentOps::poDetail($po->fresh(), $request->user())]);
    }
}
