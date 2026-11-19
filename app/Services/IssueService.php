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
use App\Models\Project;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Support\Decimal;
use App\Support\Idempotency;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Controlled, race-safe issuance (brief §5).
 *
 * Inside one transaction: lock the request → lock its lines → lock balances (ascending item_id),
 * re-validate qty ≤ outstanding approved and qty ≤ on hand against the locked rows, then write
 * the voucher, issue lines, stock decrement and ledger movement together. A short issue (less stock than
 * approved) also raises a restock requisition for the balance (RestockService).
 */
class IssueService
{
    public function __construct(private WorkflowNotifier $notifier, private RestockService $restock)
    {
    }

    /**
     * @param array<int|string, mixed> $qtys request_line_id => qty to issue now
     * @param ?int $storeId store to issue from (default: main store); the storekeeper must be assigned to it
     * @param ?string $idempotencyKey one-time form key; a repeated post returns the first voucher
     */
    public function issue(User $storekeeper, MaterialRequest $req, array $qtys, string $recipientName, ?string $notes = null,
        ?int $storeId = null, ?string $idempotencyKey = null): MaterialIssue
    {
        if ($storekeeper->role !== Role::Storekeeper || ! $storekeeper->is_active) {
            throw new AuthorizationException('Only the storekeeper can issue materials.');
        }
        if (! Project::visibleTo($storekeeper)->whereKey($req->project_id)->exists()) {
            throw new AuthorizationException('You are not assigned to this project. Ask the administrator to add the project to your account.');
        }
        $storeId ??= Store::DEFAULT_ID;
        $store = Store::assertOperable($storekeeper, $storeId);
        if ($store->project_id !== null && $store->project_id !== $req->project_id) {
            throw new WorkflowException("{$store->name} is a site store for another project and cannot issue to {$req->project->code}.");
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

        return Idempotency::run(MaterialIssue::class, $idempotencyKey, fn () => DB::transaction(function () use ($storekeeper, $req, $wanted, $recipientName, $notes, $storeId, $idempotencyKey) {
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
            // Lock order everywhere: request → lines → items → balances (ascending ids).
            $items = InventoryItem::whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $balances = InventoryBalance::lockRows($storeId, $itemIds);

            // Validate everything and price the voucher before writing anything.
            // More than the store holds: issue what is in stock; the rest stays outstanding on the request.
            $total = '0';
            $short = [];
            foreach ($wanted as $lineId => $qty) {
                $line = $lines[$lineId];
                $item = $items[$line->item_id];
                $outstanding = $line->outstanding();
                if (Decimal::cmp($qty, $outstanding) > 0) {
                    throw new WorkflowException("{$item->name}: cannot issue {$qty}; only {$outstanding} approved quantity remains.");
                }
                $onHand = (string) $balances[$line->item_id]->on_hand;
                if (Decimal::cmp($qty, $onHand) > 0) {
                    $short[] = $item->name;
                    $qty = Decimal::cmp($onHand, '0') > 0 ? Decimal::of($onHand) : null;
                }
                if ($qty === null) {
                    unset($wanted[$lineId]);

                    continue;
                }
                $wanted[$lineId] = $qty;
                $total = bcadd($total, bcmul($qty, Decimal::of($item->avg_cost, 4), 2), 2);
            }
            if (! $wanted) {
                throw new WorkflowException('Nothing to issue: '.implode(', ', $short).' '.(count($short) === 1 ? 'is' : 'are')
                    .' out of stock at this store. Use "Request restock only" to ask for more.');
            }

            $issue = MaterialIssue::create([
                'issue_no' => DocumentSequence::next('IV'), 'material_request_id' => $req->id,
                'project_id' => $req->project_id, 'store_id' => $storeId, 'idempotency_key' => $idempotencyKey, 'issued_by' => $storekeeper->id,
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
                    'item_id' => $item->id, 'store_id' => $storeId, 'movement_type' => 'issue', 'qty_delta' => bcmul($qty, '-1', 3),
                    'unit_cost' => $cost, 'balance_after' => $newOnHand, 'project_id' => $req->project_id,
                    'source_type' => 'material_issue', 'source_id' => $issue->id, 'actor_id' => $storekeeper->id,
                ]);
            }

            $fully = $lines->every(fn ($l) => Decimal::cmp($l->outstanding(), '0') <= 0);
            $req->forceFill(['status' => $fully ? RequestStatus::FullyIssued : RequestStatus::PartiallyIssued])->save();

            ActivityLog::record('issue.created', "Voucher {$issue->issue_no} issued for {$req->request_no} to {$recipientName}", $issue, ['value' => $total]);
            $this->notifier->issued($issue);
            // Anything still outstanding that this store cannot cover goes to the Overall Manager as a restock requisition.
            if (! $fully) {
                $issue->setRelation('restock', $this->restock->requestFor($storekeeper, $req, $storeId));
            }

            return $issue;
        }));
    }

    /** D8: requester confirms collection, or storekeeper records the named recipient's acknowledgment. */
    public function acknowledge(User $user, MaterialIssue $issue, string $recipientName, ?string $remarks = null): IssueAcknowledgment
    {
        $req = $issue->request;
        if ($user->id !== $req->requester_id
            && ($user->role !== Role::Storekeeper || ! MaterialIssue::visibleTo($user)->whereKey($issue->id)->exists())) {
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
            $this->notifier->acknowledged($issue->setRelation('acknowledgment', $ack), $user);

            return $ack;
        });
    }
}
