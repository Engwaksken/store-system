<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Models\AppSetting;
use App\Models\ExpenseClaim;
use App\Models\InventoryItem;
use App\Models\MaterialRequest;
use App\Models\User;
use App\Services\ExpenseClaimService;
use App\Support\Currency;
use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

/** JSON shapes of the mobile API (docs/API.md). Money and quantities are strings; timestamps ISO 8601. */
final class Present
{
    public static function time(?CarbonInterface $t): ?string
    {
        return $t?->toIso8601String();
    }

    public static function qty($v): ?string
    {
        return $v === null ? null : number_format((float) $v, 3, '.', '');
    }

    public static function money($v): ?string
    {
        return $v === null ? null : number_format((float) $v, 2, '.', '');
    }

    public static function user(User $u): array
    {
        $as = Gate::forUser($u);

        return [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone,
            'role' => $u->role->value, 'role_label' => $u->role->label(),
            'must_change_password' => (bool) $u->must_change_password,
            'currency' => Currency::code(), 'currency_decimals' => Currency::decimals(),
            'company_name' => AppSetting::get('company_name') ?: config('app.name'),
            'can' => [
                'approve_requests' => $as->allows('approve-any'),
                'create_request' => $as->allows('create-request'),
                'submit_claim' => $as->allows('submit-claim'),
                'decide_claims' => $u->hasRole(Role::Admin, Role::OverallManager, Role::ProjectManager),
                'view_stock_value' => $as->allows('view-stock-value'),
                'view_claims' => true,
            ],
        ];
    }

    public static function project($p): ?array
    {
        return $p ? ['id' => $p->id, 'code' => $p->code, 'name' => $p->name] : null;
    }

    public static function person($u): ?array
    {
        return $u ? ['id' => $u->id, 'name' => $u->name] : null;
    }

    public static function requestSummary(MaterialRequest $r): array
    {
        return [
            'id' => $r->id, 'request_no' => $r->request_no, 'version' => $r->version,
            'status' => $r->status->value, 'status_label' => $r->status->label(), 'status_tone' => $r->status->tone(),
            'project' => self::project($r->project), 'requester' => self::person($r->requester),
            'purpose' => $r->purpose, 'needed_on' => $r->needed_on?->toDateString(),
            'lines_count' => $r->lines_count ?? ($r->relationLoaded('lines') ? $r->lines->count() : $r->lines()->count()),
            'submitted_at' => self::time($r->submitted_at), 'created_at' => self::time($r->created_at),
        ];
    }

    public static function requestDetail(MaterialRequest $r, User $viewer): array
    {
        $r->loadMissing('project', 'requester', 'projectManager', 'lines.item.unit', 'lines.item.balances', 'approvals.approver');
        $as = Gate::forUser($viewer);
        $step = $as->allows('pm-decide', $r) ? 'pm' : ($as->allows('overall-decide', $r) ? 'overall' : null);

        return self::requestSummary($r) + [
            'project_manager' => self::person($r->projectManager),
            'lines' => $r->lines->map(fn ($l) => [
                'id' => $l->id,
                'item' => ['id' => $l->item->id, 'sku' => $l->item->sku, 'name' => $l->item->name, 'unit' => $l->item->unit?->abbreviation],
                'requested_qty' => self::qty($l->requested_qty), 'pm_approved_qty' => self::qty($l->pm_approved_qty),
                'final_approved_qty' => self::qty($l->final_approved_qty), 'issued_qty' => self::qty($l->issued_qty),
                'on_hand' => self::qty($l->item->onHand()), 'notes' => $l->notes,
            ])->values()->all(),
            'approvals' => $r->approvals->sortBy('id')->map(fn ($a) => [
                'step' => $a->step, 'version' => $a->version, 'decision' => $a->decision, 'reason' => $a->reason,
                'approver' => self::person($a->approver), 'was_substitute' => (bool) $a->was_substitute, 'decided_at' => self::time($a->decided_at),
            ])->values()->all(),
            'can' => [
                'decide' => $step !== null,
                'decision_step' => $step,
                'max_qty_field' => $step === 'pm' ? 'requested_qty' : ($step === 'overall' ? 'pm_approved_qty' : null),
                'cancel' => ($viewer->id === $r->requester_id || $viewer->isAdmin())
                    && in_array($r->status, [\App\Enums\RequestStatus::Draft, \App\Enums\RequestStatus::Submitted], true),
            ],
        ];
    }

    public static function item(InventoryItem $i, User $viewer, ?int $storeId = null, bool $withBalances = false): array
    {
        $data = [
            'id' => $i->id, 'sku' => $i->sku, 'name' => $i->name, 'category' => $i->category?->name, 'unit' => $i->unit?->abbreviation,
            'on_hand' => self::qty($i->onHand($storeId)), 'min_level' => self::qty($i->min_level),
            'low' => $i->isLowStock(), 'is_active' => (bool) $i->is_active,
        ];
        if (Gate::forUser($viewer)->allows('view-stock-value')) {
            $data['avg_cost'] = self::money($i->avg_cost);
        }
        if ($withBalances) {
            $data['balances'] = $i->balances->sortBy('store_id')->map(fn ($b) => [
                'store' => $b->store ? ['id' => $b->store->id, 'code' => $b->store->code, 'name' => $b->store->name] : null,
                'on_hand' => self::qty($b->on_hand),
            ])->values()->all();
        }

        return $data;
    }

    public static function claim(ExpenseClaim $c, User $viewer): array
    {
        $svc = app(ExpenseClaimService::class);

        return [
            'id' => $c->id, 'claim_no' => $c->claim_no,
            'status' => $c->status, 'status_label' => $c->statusLabel(), 'status_tone' => $c->tone(),
            'project' => self::project($c->project), 'claimant' => self::person($c->claimant),
            'category' => $c->category ? ['id' => $c->category->id, 'name' => $c->category->name] : null,
            'cost_code' => $c->costCode ? ['id' => $c->costCode->id, 'code' => $c->costCode->code, 'name' => $c->costCode->name] : null,
            'incurred_on' => $c->incurred_on?->toDateString(), 'amount' => self::money($c->amount), 'description' => $c->description,
            'has_receipt' => (bool) $c->receipt_path,
            'receipt_url' => $c->receipt_path ? route('api.claims.receipt', $c) : null,
            'decision_reason' => $c->decision_reason, 'updated_at' => self::time($c->updated_at),
            'can' => ['decide' => $svc->canDecide($viewer, $c), 'edit' => $svc->canEdit($viewer, $c), 'delete' => $svc->canDelete($viewer, $c)],
        ];
    }

    public static function notification(DatabaseNotification $n): array
    {
        $data = $n->data;
        $path = parse_url((string) ($data['url'] ?? ''), PHP_URL_PATH) ?? '';
        $target = match (true) {
            (bool) preg_match('#/requests/(\d+)$#', $path, $m) => ['type' => 'request', 'id' => (int) $m[1]],
            (bool) preg_match('#/claims/(\d+)$#', $path, $m) => ['type' => 'claim', 'id' => (int) $m[1]],
            default => ['type' => null, 'id' => null],
        };

        return [
            'id' => $n->id, 'subject' => $data['subject'] ?? '', 'body' => $data['body'] ?? '', 'kind' => $data['kind'] ?? 'info',
            'read' => $n->read_at !== null, 'created_at' => self::time($n->created_at), 'target' => $target,
        ];
    }
}
