<?php

namespace Tests\Feature;

use App\Models\PurchaseRequisition;
use App\Models\Store;
use App\Models\User;
use App\Services\PurchasingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Mobile API v1: issuing, vouchers, receipts, adjustments, transfers, returns and purchasing approvals. */
class MobileStoreOpsApiTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->buildFixtures();
    }

    private function tokenFor(User $u): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$u->createToken('test')->plainTextToken];
    }

    public function test_me_reports_store_and_purchasing_permissions(): void
    {
        $this->getJson('/api/v1/me', $this->tokenFor($this->store))->assertOk()
            ->assertJsonPath('user.can.issue_stock', true)->assertJsonPath('user.can.manage_stock', true)
            ->assertJsonPath('user.can.decide_purchasing', false)->assertJsonPath('user.can.view_stock_value', false);
        $this->getJson('/api/v1/me', $this->tokenFor($this->overall))->assertOk()
            ->assertJsonPath('user.can.issue_stock', false)->assertJsonPath('user.can.decide_purchasing', true)
            ->assertJsonPath('user.can.view_stock_value', true);
    }

    public function test_storekeeper_issues_a_short_request_and_the_balance_goes_to_restocking(): void
    {
        $this->receive($this->cement, 6);
        $req = $this->approvedRequest(10);
        $sk = $this->tokenFor($this->store);

        $this->getJson('/api/v1/issues/queue', $sk)->assertOk()->assertJsonPath('data.0.request_no', $req->request_no);
        $line = $req->lines()->first();
        $this->getJson("/api/v1/requests/{$req->id}/issue", $sk)->assertOk()
            ->assertJsonPath('data.lines.0.max_issue', '6.000')->assertJsonPath('data.shortfall.0.restock_qty', '5.000')
            ->assertJsonPath('data.recipient_name', $this->staff->name);

        $res = $this->postJson("/api/v1/requests/{$req->id}/issue", [
            'qty' => [$line->id => 6], 'recipient_name' => 'Site foreman', 'store_id' => Store::DEFAULT_ID, 'idempotency_key' => 'k-1',
        ], $sk)->assertCreated()->assertJsonPath('data.lines.0.qty', '6.000')->assertJsonMissingPath('data.total_value');
        $pr = PurchaseRequisition::sole();
        $res->assertJsonPath('restock.pr_no', $pr->pr_no);
        $issueId = $res->json('data.id');

        // Same key again: no second voucher.
        $this->postJson("/api/v1/requests/{$req->id}/issue", ['qty' => [$line->id => 6], 'recipient_name' => 'Site foreman', 'idempotency_key' => 'k-1'], $sk)
            ->assertOk()->assertJsonPath('replayed', true);

        // The requester sees and acknowledges the voucher.
        $staff = $this->tokenFor($this->staff);
        $this->getJson('/api/v1/issues', $staff)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/issues/{$issueId}", $staff)->assertOk()->assertJsonPath('data.can.acknowledge', true);
        $this->postJson("/api/v1/issues/{$issueId}/acknowledge", ['remarks' => 'All good'], $staff)->assertOk()
            ->assertJsonPath('data.acknowledged', true)->assertJsonPath('data.can.acknowledge', false);

        // The Overall Manager sees the restock requisition and approves it.
        $om = $this->tokenFor($this->overall);
        $this->getJson('/api/v1/purchase-requisitions?restock=1', $om)->assertOk()->assertJsonPath('data.0.material_request.request_no', $req->request_no);
        $this->getJson("/api/v1/purchase-requisitions/{$pr->id}", $om)->assertOk()->assertJsonPath('data.can.decide', true);
        $this->postJson("/api/v1/purchase-requisitions/{$pr->id}/decision", ['decision' => 'approved'], $om)->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_restock_only_and_issue_permissions(): void
    {
        $req = $this->approvedRequest(10);
        $this->postJson("/api/v1/requests/{$req->id}/restock", [], $this->tokenFor($this->staff))->assertForbidden();
        $this->getJson("/api/v1/requests/{$req->id}/issue", $this->tokenFor($this->staff))->assertForbidden();
        $this->postJson("/api/v1/requests/{$req->id}/restock", [], $this->tokenFor($this->store))->assertCreated()->assertJsonPath('data.pr_no', PurchaseRequisition::sole()->pr_no);
        $this->postJson("/api/v1/requests/{$req->id}/restock", [], $this->tokenFor($this->store))->assertOk()->assertJsonPath('data', null);
    }

    public function test_receipt_and_adjustment_from_mobile(): void
    {
        $sk = $this->tokenFor($this->store);
        $this->getJson('/api/v1/store-ops/options', $sk)->assertOk()->assertJsonPath('operable_stores.0.id', Store::DEFAULT_ID);
        $id = $this->postJson('/api/v1/receipts', [
            'store_id' => Store::DEFAULT_ID, 'received_on' => now()->toDateString(), 'supplier_name' => 'Hima', 'reference' => 'DN-9',
            'lines' => [['item_id' => $this->cement->id, 'qty' => 20, 'unit_cost' => 31000]],
        ], $sk)->assertCreated()->assertJsonMissingPath('data.total_value')->json('data.id');
        $this->assertSame('20.000', $this->onHand($this->cement));
        $this->getJson("/api/v1/receipts/{$id}", $this->tokenFor($this->admin))->assertOk()->assertJsonPath('data.total_value', '620000.00');
        $this->getJson('/api/v1/receipts', $sk)->assertOk()->assertJsonPath('meta.total', 1);

        $this->postJson('/api/v1/adjustments', ['store_id' => Store::DEFAULT_ID, 'item_id' => $this->cement->id, 'qty_delta' => -2, 'reason' => 'Two bags damaged by rain'], $this->tokenFor($this->store))
            ->assertCreated()->assertJsonPath('data.on_hand', '18.000');
        $this->postJson('/api/v1/receipts', ['store_id' => 1, 'received_on' => now()->toDateString(), 'supplier_name' => 'X', 'lines' => [['item_id' => $this->cement->id, 'qty' => 1, 'unit_cost' => 1]]], $this->tokenFor($this->staff))
            ->assertForbidden();
    }

    public function test_transfer_dispatch_receive_and_return_from_mobile(): void
    {
        $site = Store::create(['code' => 'S2', 'name' => 'Site store', 'is_active' => true]);
        $receiver = User::factory()->role(\App\Enums\Role::Storekeeper)->create();
        $receiver->stores()->attach($site->id);
        $this->receive($this->cement, 30);

        $tid = $this->postJson('/api/v1/transfers', [
            'from_store_id' => Store::DEFAULT_ID, 'to_store_id' => $site->id, 'lines' => [['item_id' => $this->cement->id, 'qty' => 10]],
        ], $this->tokenFor($this->store))->assertCreated()->assertJsonPath('data.status', 'in_transit')->json('data.id');
        $lineId = $this->getJson("/api/v1/transfers/{$tid}", $this->tokenFor($receiver))->assertOk()->assertJsonPath('data.can.receive', true)->json('data.lines.0.id');
        $this->postJson("/api/v1/transfers/{$tid}/receive", ['received' => [$lineId => 9], 'reason' => [$lineId => 'One bag burst']], $this->tokenFor($receiver))
            ->assertOk()->assertJsonPath('data.status', 'received')->assertJsonPath('data.lines.0.received_qty', '9.000');
        $this->getJson('/api/v1/transfers', $this->tokenFor($this->store))->assertOk()->assertJsonPath('meta.total', 1);

        // Issue, then return part of it.
        $req = $this->approvedRequest(5);
        $issue = app(\App\Services\IssueService::class)->issue($this->store, $req, [$req->lines()->first()->id => 5], 'R');
        $sk = $this->tokenFor($this->store);
        $this->getJson("/api/v1/issues/{$issue->id}", $sk)->assertOk()->assertJsonPath('data.can.return', true);
        $issueLine = $issue->lines()->first();
        $rid = $this->postJson("/api/v1/issues/{$issue->id}/return", [
            'qty' => [$issueLine->id => 2], 'store_id' => Store::DEFAULT_ID, 'returned_by_name' => 'Foreman', 'reason' => 'Slab finished early',
        ], $sk)->assertCreated()->assertJsonPath('data.lines.0.qty', '2.000')->json('data.id');
        $this->getJson("/api/v1/returns/{$rid}", $sk)->assertOk()->assertJsonPath('data.issue.issue_no', $issue->issue_no);
        $this->getJson('/api/v1/returns', $this->tokenFor($this->staff))->assertForbidden();
    }

    public function test_purchase_order_approval_from_mobile(): void
    {
        $buyer = User::factory()->role(\App\Enums\Role::Procurement)->create();
        $supplier = \App\Models\Supplier::create(['code' => 'HIMA', 'name' => 'Hima Cement', 'is_active' => true]);
        $svc = app(PurchasingService::class);
        $po = $svc->createOrder($buyer, ['supplier_id' => $supplier->id, 'store_id' => Store::DEFAULT_ID, 'order_date' => now()->toDateString()],
            [['item_id' => $this->cement->id, 'qty' => 10, 'unit_price' => 30000]]);

        $om = $this->tokenFor($this->overall);
        $this->getJson('/api/v1/purchase-orders', $om)->assertOk()->assertJsonPath('data.0.po_no', $po->po_no);
        $detail = $this->getJson("/api/v1/purchase-orders/{$po->id}", $om)->assertOk()->assertJsonPath('data.can.decide', true);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/decision", ['decision' => 'rejected', 'lock_version' => $detail->json('data.lock_version')], $om)
            ->assertUnprocessable();
        $this->postJson("/api/v1/purchase-orders/{$po->id}/decision", ['decision' => 'approved', 'lock_version' => $detail->json('data.lock_version')], $om)
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->getJson('/api/v1/purchase-orders', $this->tokenFor($this->staff))->assertForbidden();
    }
}
