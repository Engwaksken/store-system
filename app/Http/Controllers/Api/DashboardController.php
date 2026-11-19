<?php

namespace App\Http\Controllers\Api;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\ExpenseClaim;
use App\Models\InventoryItem;
use App\Models\MaterialIssue;
use App\Models\MaterialRequest;
use App\Services\ExpenseClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Role-aware headline cards for the app's Home tab. */
class DashboardController extends ApiController
{
    public function __invoke(Request $request, MaterialRequestController $requests, ExpenseClaimService $claims): JsonResponse
    {
        $user = $request->user();
        $cards = [];
        $awaiting = collect();

        if (Gate::allows('approve-any')) {
            $awaiting = $requests->awaiting($request);
            $cards[] = ['key' => 'awaiting_me', 'label' => 'Waiting for my approval', 'value' => $awaiting->count(), 'tone' => $awaiting->isNotEmpty() ? 'gold' : ''];
        }
        if (Gate::allows('create-request')) {
            $open = MaterialRequest::where('requester_id', $user->id)
                ->whereIn('status', [RequestStatus::Draft->value, RequestStatus::Submitted->value, RequestStatus::PmApproved->value,
                    RequestStatus::OverallApproved->value, RequestStatus::PartiallyIssued->value])->count();
            $cards[] = ['key' => 'my_open_requests', 'label' => 'My open requests', 'value' => $open, 'tone' => 'navy'];
        }
        if ($user->role === Role::Storekeeper) {
            $ready = MaterialRequest::readyToIssueBy($user)->count();
            $cards[] = ['key' => 'ready_to_issue', 'label' => 'Ready to issue', 'value' => $ready, 'tone' => $ready ? 'gold' : ''];
        }
        if (Gate::allows('view-stock-ledger')) {
            $low = InventoryItem::with('balances')->where('is_active', true)->get()->filter->isLowStock()->count();
            $cards[] = ['key' => 'low_stock', 'label' => 'Low-stock items', 'value' => $low, 'tone' => $low ? 'bad' : 'ok'];
            $out = InventoryItem::where('is_active', true)
                ->whereRaw('(select coalesce(sum(b.on_hand), 0) from inventory_balances b where b.item_id = inventory_items.id) <= 0')->count();
            $cards[] = ['key' => 'out_of_stock', 'label' => 'Out of stock', 'value' => $out, 'tone' => $out ? 'bad' : 'ok'];
        }
        // Issued materials (everyone sees the vouchers they may open).
        $issued = MaterialIssue::statsFor($user);
        $cards[] = ['key' => 'issued_vouchers_month', 'label' => 'Issue vouchers this month', 'value' => $issued['vouchers_month'], 'tone' => 'navy'];
        $cards[] = ['key' => 'issued_items_month', 'label' => 'Items issued this month', 'value' => $issued['items_month'], 'tone' => ''];
        $cards[] = ['key' => 'issues_unacknowledged', 'label' => 'Vouchers not acknowledged', 'value' => $issued['unacknowledged'], 'tone' => $issued['unacknowledged'] ? 'gold' : 'ok'];

        $claimDecisions = ExpenseClaim::visibleTo($user)->with('project')->where('status', 'submitted')->get()
            ->filter(fn ($c) => $claims->canDecide($user, $c))->count();
        if ($claimDecisions || $user->hasRole(Role::Admin, Role::OverallManager, Role::ProjectManager)) {
            $cards[] = ['key' => 'claims_awaiting', 'label' => 'Claims to approve', 'value' => $claimDecisions, 'tone' => $claimDecisions ? 'gold' : ''];
        }
        if ($user->hasRole(Role::Admin, Role::Accountant)) {
            $unpaid = ExpenseClaim::where('status', 'approved')->count();
            $cards[] = ['key' => 'claims_unpaid', 'label' => 'Approved claims unpaid', 'value' => $unpaid, 'tone' => $unpaid ? 'bad' : ''];
        }

        return response()->json([
            'cards' => $cards,
            'awaiting_me' => $awaiting->take(5)->map(fn ($r) => Present::requestSummary($r))->values(),
            'unread_notifications' => $user->unreadNotifications()->count(),
        ]);
    }
}
