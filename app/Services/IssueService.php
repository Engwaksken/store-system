<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Exceptions\WorkflowException;
use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\IssueAcknowledgment;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueLine;
use App\Models\MaterialRequest;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Controlled, race-safe issuance (brief §5).
 *
 * Inside one transaction: lock the request → lock its lines → lock balances (ascending item_id),
 * re-validate qty ≤ outstanding approved and qty ≤ on hand against the locked rows, then write
 * the voucher, issue lines, stock decrement and ledger movement together.
 */
class IssueService
{
    public function __construct(private WorkflowNotifier $notifier)
    {
    }

    /** @param array<int|string, mixed> $qtys request_line_id => qty to issue now */
    public function issue(User $storekeeper, MaterialRequest $req, array $qtys, string $recipientName, ?string $notes = null): MaterialIssue
    {
        if ($storekeeper->role !== Role::Storekeeper || ! $storekeeper->is_active) {
            throw new AuthorizationException('Only the storekeeper can issue materials.');
        }
        $recipientName = trim($recipientName);
        if ($recipientName === '') {
            throw new WorkflowException('Recipient name is required.');
        }

        $wanted = [];
        foreach ($qtys as $lineId => $qty) {
            if ($qty === null || $qty === '') {
                continue;
            }
            $qty = Decimal::of($qty);
            if (Decimal::cmp($qty, '0') < 0) {
                throw new WorkflowException('Issue quantities cannot be negative.');
            }
            if (Decimal::cmp($qty, '0') > 0) {
                $wanted[(int) $lineId] = $qty;
            }
        }
        if (! $wanted) {
            throw new WorkflowException('Enter a quantity for at least one item.');
        }

        return DB::transaction(function () use ($storekeeper, $req, $wanted, $recipientName, $notes) {
            $req = MaterialRequest::whereKey($req->id)->lockForUpdate()->firstOrFail();
            if (! $req->status->isIssuable()) {
                throw new WorkflowException("Request {$req->request_no} is {$req->status->label()} and cannot be issued.");
            }

            $lines = $req->lines()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach (array_keys($wanted) as $lineId) {
                if (! $lines->has($lineId)) {
                    throw new WorkflowException('Line does not belong to this request.');
                }
            }

            $itemIds = $lines->only(array_keys($wanted))->pluck('item_id')->unique()->sort()->values()->all();
            $balances = InventoryBalance::whereIn('item_id', $itemIds)->orderBy('item_id')->lockForUpdate()->get()->keyBy('item_id');
            $items = InventoryItem::whereIn('id', $itemIds)->get()->keyBy('id');

            // Validate everything and price the voucher before writing anything.
            $total = '0';
            foreach ($wanted as $lineId => $qty) {
                $line = $lines[$lineId];
                $item = $items[$line->item_id];
                $outstanding = $line->outstanding();
                if (Decimal::cmp($qty, $outstanding) > 0) {
                    throw new WorkflowException("{$item->name}: cannot issue {$qty}; only {$outstanding} approved quantity remains.");
                }
                $onHand = (string) $balances[$line->item_id]->on_hand;
                if (Decimal::cmp($qty, $onHand) > 0) {
                    throw new WorkflowException("{$item->name}: cannot issue {$qty}; only {$onHand} in stock.");
                }
                $total = bcadd($total, bcmul($qty, Decimal::of($item->avg_cost, 4), 2), 2);
            }

            $issue = MaterialIssue::create([
                'issue_no' => DocumentSequence::next('IV'), 'material_request_id' => $req->id,
                'project_id' => $req->project_id, 'issued_by' => $storekeeper->id,
                'recipient_name' => $recipientName, 'issued_at' => now(), 'total_value' => $total, 'notes' => $notes,
            ]);

            foreach ($wanted as $lineId => $qty) {
                $line = $lines[$lineId];
                $item = $items[$line->item_id];
                $balance = $balances[$line->item_id];
                $cost = Decimal::of($item->avg_cost, 4);
                $value = bcmul($qty, $cost, 2);
                $newOnHand = bcsub((string) $balance->on_hand, $qty, 3);

                MaterialIssueLine::create([
                    'material_issue_id' => $issue->id, 'material_request_line_id' => $line->id, 'item_id' => $item->id,
                    'qty' => $qty, 'unit_cost_snapshot' => $cost, 'line_value' => $value,
                ]);
                $line->forceFill(['issued_qty' => bcadd((string) $line->issued_qty, $qty, 3)])->save();
                $balance->update(['on_hand' => $newOnHand]);
                StockMovement::create([
                    'item_id' => $item->id, 'movement_type' => 'issue', 'qty_delta' => bcmul($qty, '-1', 3),
                    'unit_cost' => $cost, 'balance_after' => $newOnHand, 'project_id' => $req->project_id,
                    'source_type' => 'material_issue', 'source_id' => $issue->id, 'actor_id' => $storekeeper->id,
                ]);
            }

            $fully = $lines->every(fn ($l) => Decimal::cmp($l->outstanding(), '0') <= 0);
            $req->forceFill(['status' => $fully ? RequestStatus::FullyIssued : RequestStatus::PartiallyIssued])->save();

            ActivityLog::record('issue.created', "Voucher {$issue->issue_no} issued for {$req->request_no} to {$recipientName}", $issue, ['value' => $total]);
            $this->notifier->issued($issue);

            return $issue;
        });
    }

    /** D8: requester confirms collection, or storekeeper records the named recipient's acknowledgment. */
    public function acknowledge(User $user, MaterialIssue $issue, string $recipientName, ?string $remarks = null): IssueAcknowledgment
    {
        $req = $issue->request;
        if ($user->id !== $req->requester_id && $user->role !== Role::Storekeeper) {
            throw new AuthorizationException('Only the requester or storekeeper can acknowledge this voucher.');
        }

        return DB::transaction(function () use ($user, $issue, $recipientName, $remarks) {
            MaterialIssue::whereKey($issue->id)->lockForUpdate()->first();
            if (IssueAcknowledgment::where('material_issue_id', $issue->id)->exists()) {
                throw new WorkflowException("Voucher {$issue->issue_no} is already acknowledged.");
            }
            $ack = IssueAcknowledgment::create([
                'material_issue_id' => $issue->id, 'acknowledged_by' => $user->id,
                'recipient_name' => trim($recipientName) ?: $user->name, 'remarks' => $remarks, 'acknowledged_at' => now(),
            ]);
            ActivityLog::record('issue.acknowledged', "Voucher {$issue->issue_no} acknowledged by {$ack->recipient_name}", $issue);

            return $ack;
        });
    }
}
