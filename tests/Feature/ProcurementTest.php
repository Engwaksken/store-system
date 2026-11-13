<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exceptions\WorkflowException;
use App\Models\AppSetting;
use App\Models\InventoryBalance;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseInvoiceService;
use App\Services\PurchasingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Phase 3: PR → quotes → PO → GRN → invoice (three-way match) → payment. */
class ProcurementTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    private User $buyer;
    private Supplier $s1, $s2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
        $this->buyer = User::factory()->role(Role::Procurement)->create();
        $this->s1 = Supplier::create(['code' => 'S1', 'name' => 'Cheap Hardware']);
        $this->s2 = Supplier::create(['code' => 'S2', 'name' => 'Fast Hardware']);
    }

    private function svc(): PurchasingService
    {
        return app(PurchasingService::class);
    }

    private function approvedPr($qty = 100): PurchaseRequisition
    {
        $pr = $this->svc()->createRequisition($this->store, ['store_id' => Store::DEFAULT_ID, 'purpose' => 'Restock cement'],
            [['item_id' => $this->cement->id, 'qty' => $qty, 'est_unit_cost' => 30000]]);
        $this->svc()->submitRequisition($this->store, $pr);

        return $this->svc()->decideRequisition($this->overall, $pr, 'approved', null);
    }

    private function approvedPo($qty = 100, $price = 30000): PurchaseOrder
    {
        $po = $this->svc()->createOrder($this->buyer, ['supplier_id' => $this->s1->id, 'store_id' => Store::DEFAULT_ID],
            [['item_id' => $this->cement->id, 'qty' => $qty, 'unit_price' => $price]]);

        return $this->svc()->decideOrder($this->overall, $po, 'approved', null, $po->lock_version);
    }

    public function test_full_procure_to_pay_cycle(): void
    {
        $pr = $this->approvedPr(100);
        $this->svc()->addQuote($this->buyer, $pr, $this->s1->id, [$pr->lines()->sole()->id => 29000]);
        $q2 = $this->svc()->addQuote($this->buyer, $pr, $this->s2->id, [$pr->lines()->sole()->id => 31000]);
        $q1 = $pr->quotes()->where('supplier_id', $this->s1->id)->sole();
        $this->svc()->selectQuote($this->buyer, $q1, null);

        // HTTP: PO from the PR at the quoted price
        $this->actingAs($this->buyer)->post(route('pos.store'), [
            'purchase_requisition_id' => $pr->id, 'supplier_id' => $this->s1->id, 'store_id' => Store::DEFAULT_ID, 'order_date' => now()->toDateString(),
            'lines' => [['item_id' => $this->cement->id, 'qty' => 100, 'unit_price' => 29000]],
        ])->assertRedirect();
        $po = PurchaseOrder::sole();
        $this->assertSame('2900000.00', (string) $po->total);
        $this->assertSame('ordered', $pr->fresh()->status);

        $this->actingAs($this->buyer)->post(route('pos.decide', $po), ['decision' => 'approved', 'lock_version' => 0])->assertForbidden();
        $this->actingAs($this->overall)->post(route('pos.decide', $po), ['decision' => 'approved', 'lock_version' => 0])->assertRedirect();
        $this->assertSame('approved', $po->fresh()->status);

        $line = $po->lines()->sole();
        $grn = app(GoodsReceiptService::class);
        $grn->receive($this->store, $po->fresh(), [$line->id => 60]);
        $this->assertSame('partially_received', $po->fresh()->status);
        $this->actingAs($this->store)->post(route('pos.receive.store', $po), ['qty' => [$line->id => 40], 'received_on' => now()->toDateString()])->assertRedirect();
        $this->assertSame('received', $po->fresh()->status);
        $this->assertSame('100.000', $this->onHand($this->cement));
        $this->assertSame('29000.0000', (string) $this->cement->fresh()->avg_cost);
        $this->assertSame(2, StockReceipt::where('purchase_order_id', $po->id)->count());

        $this->actingAs($this->accountant)->post(route('invoices.store', $po), [
            'supplier_invoice_no' => 'INV-1', 'invoice_date' => now()->toDateString(), 'tax' => 0,
            'lines' => [$line->id => ['qty' => 100, 'unit_price' => 29000]],
        ])->assertRedirect();
        $inv = PurchaseInvoice::sole();
        $this->assertSame('matched', $inv->match_status);

        $pay = app(PurchaseInvoiceService::class);
        $pay->pay($this->accountant, $inv, '1000000', now()->toDateString(), 'bank_transfer', 'TT-1');
        $this->assertSame('partially_paid', $inv->fresh()->payment_status);
        $pay->pay($this->accountant, $inv->fresh(), '1900000', now()->toDateString(), 'cheque', 'CHQ-2');
        $this->assertSame('paid', $inv->fresh()->payment_status);
        $this->assertSame('0.00', $inv->fresh()->balance());

        // Ledger still reconciles.
        $b = InventoryBalance::where('item_id', $this->cement->id)->sole();
        $this->assertSame((string) $b->on_hand, StockMovement::where('item_id', $this->cement->id)->get()->reduce(fn ($c, $m) => bcadd($c, (string) $m->qty_delta, 3), '0.000'));
    }

    public function test_requester_cannot_approve_own_pr_and_rejection_needs_reason(): void
    {
        $pr = $this->svc()->createRequisition($this->admin, ['store_id' => Store::DEFAULT_ID, 'purpose' => 'x'], [['item_id' => $this->cement->id, 'qty' => 1]]);
        $this->svc()->submitRequisition($this->admin, $pr);
        try {
            $this->svc()->decideRequisition($this->admin, $pr, 'approved', null);
            $this->fail('Self-approval allowed');
        } catch (AuthorizationException) {
        }
        $this->actingAs($this->overall)->post(route('prs.decide', $pr), ['decision' => 'rejected'])->assertSessionHasErrors('reason');
        $this->actingAs($this->pm)->post(route('prs.decide', $pr), ['decision' => 'approved'])->assertForbidden();
        $this->assertSame('submitted', $pr->fresh()->status);
    }

    public function test_pm_raises_pr_only_for_own_project(): void
    {
        $other = \App\Models\Project::where('code', 'P-2')->sole();
        $this->expectException(AuthorizationException::class);
        $this->svc()->createRequisition($this->pm, ['project_id' => $other->id, 'store_id' => Store::DEFAULT_ID, 'purpose' => 'x'], [['item_id' => $this->cement->id, 'qty' => 1]]);
    }

    public function test_choosing_a_dearer_quote_needs_a_reason_and_po_must_match_quote(): void
    {
        $pr = $this->approvedPr(10);
        $lineId = $pr->lines()->sole()->id;
        $this->svc()->addQuote($this->buyer, $pr, $this->s1->id, [$lineId => 100]);
        $dear = $this->svc()->addQuote($this->buyer, $pr, $this->s2->id, [$lineId => 120]);
        try {
            $this->svc()->selectQuote($this->buyer, $dear, '');
            $this->fail('Dearer quote selected without reason');
        } catch (WorkflowException) {
        }
        $this->svc()->selectQuote($this->buyer, $dear, 'Only S2 can deliver this week');

        try {
            $this->svc()->createOrder($this->buyer, ['purchase_requisition_id' => $pr->id, 'supplier_id' => $this->s1->id, 'store_id' => 1],
                [['item_id' => $this->cement->id, 'qty' => 10, 'unit_price' => 100]]);
            $this->fail('PO to non-selected supplier');
        } catch (WorkflowException) {
        }
        $this->expectException(WorkflowException::class);
        $this->svc()->createOrder($this->buyer, ['purchase_requisition_id' => $pr->id, 'supplier_id' => $this->s2->id, 'store_id' => 1],
            [['item_id' => $this->cement->id, 'qty' => 10, 'unit_price' => 110]]);
    }

    public function test_po_approval_policy_creator_threshold_and_stale_version(): void
    {
        AppSetting::put('po_owner_threshold', '1000000');
        $big = $this->svc()->createOrder($this->buyer, ['supplier_id' => $this->s1->id, 'store_id' => 1], [['item_id' => $this->cement->id, 'qty' => 100, 'unit_price' => 30000]]);
        $this->assertFalse($this->svc()->canDecideOrder($this->overall, $big), 'Overall Manager cannot approve above threshold');
        $this->assertFalse($this->svc()->canDecideOrder($this->buyer, $big), 'Creator cannot approve');
        $this->actingAs($this->overall)->post(route('pos.decide', $big), ['decision' => 'approved', 'lock_version' => 0])->assertForbidden();

        // Stale view: version changed since the approver loaded the page.
        PurchaseOrder::whereKey($big->id)->update(['lock_version' => 5]);
        try {
            $this->svc()->decideOrder($this->admin, $big->fresh(), 'approved', null, 0);
            $this->fail('Stale approval accepted');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('changed by someone else', $e->getMessage());
        }
        $this->svc()->decideOrder($this->admin, $big->fresh(), 'approved', null, 5);
        $this->assertSame('approved', $big->fresh()->status);

        // Admin who raised a PO cannot approve it either.
        $own = $this->svc()->createOrder($this->admin, ['supplier_id' => $this->s1->id, 'store_id' => 1], [['item_id' => $this->cement->id, 'qty' => 1, 'unit_price' => 1]]);
        $this->expectException(AuthorizationException::class);
        $this->svc()->decideOrder($this->admin, $own, 'approved', null);
    }

    public function test_grn_cannot_exceed_outstanding_or_precede_approval_or_use_unassigned_store(): void
    {
        $po = $this->svc()->createOrder($this->buyer, ['supplier_id' => $this->s1->id, 'store_id' => 1], [['item_id' => $this->cement->id, 'qty' => 10, 'unit_price' => 100]]);
        $line = $po->lines()->sole();
        try {
            app(GoodsReceiptService::class)->receive($this->store, $po, [$line->id => 5]);
            $this->fail('Received against unapproved PO');
        } catch (WorkflowException) {
        }
        $po = $this->svc()->decideOrder($this->overall, $po, 'approved', null);
        try {
            app(GoodsReceiptService::class)->receive($this->store, $po, [$line->id => 11]);
            $this->fail('Over-receipt allowed');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('only 10.000 still on order', $e->getMessage());
        }
        $stranger = User::factory()->role(Role::Storekeeper)->create();
        try {
            app(GoodsReceiptService::class)->receive($stranger, $po, [$line->id => 1]);
            $this->fail('Unassigned storekeeper received');
        } catch (AuthorizationException) {
        }
        $this->assertSame('0.000', $this->onHand($this->cement));
        $this->assertSame(0, StockReceipt::count());
    }

    public function test_duplicate_grn_post_does_not_double_receive(): void
    {
        $po = $this->approvedPo(10, 100);
        $payload = ['qty' => [$po->lines()->sole()->id => 4], 'received_on' => now()->toDateString(), 'idempotency_key' => 'grn-key'];
        $this->actingAs($this->store)->post(route('pos.receive.store', $po), $payload)->assertRedirect();
        $this->actingAs($this->store)->post(route('pos.receive.store', $po), $payload)->assertRedirect();
        $this->assertSame(1, StockReceipt::count());
        $this->assertSame('4.000', (string) $po->lines()->sole()->received_qty);
        $this->assertSame('4.000', $this->onHand($this->cement));
    }

    public function test_invoice_mismatch_blocks_payment_until_independent_approval(): void
    {
        $po = $this->approvedPo(10, 100);
        $line = $po->lines()->sole();
        app(GoodsReceiptService::class)->receive($this->store, $po, [$line->id => 6]);
        $svc = app(PurchaseInvoiceService::class);

        // Price differs AND invoiced qty (10) exceeds received (6).
        $inv = $svc->record($this->accountant, $po, ['supplier_invoice_no' => 'A-1', 'invoice_date' => now()->toDateString()], [$line->id => ['qty' => 10, 'unit_price' => 110]]);
        $this->assertSame('exception', $inv->match_status);
        $this->assertStringContainsString('PO price', $inv->match_notes);
        $this->assertStringContainsString('exceeds quantity received', $inv->match_notes);

        try {
            $svc->pay($this->accountant, $inv, '100', now()->toDateString(), 'cash');
            $this->fail('Paid an unapproved exception');
        } catch (WorkflowException) {
        }
        $this->actingAs($this->accountant)->post(route('invoices.approve-exception', $inv), ['reason' => 'Agreed'])->assertForbidden();
        $this->actingAs($this->overall)->post(route('invoices.approve-exception', $inv), ['reason' => 'Price rise confirmed by letter'])->assertRedirect();
        $this->assertSame('exception_approved', $inv->fresh()->match_status);

        try {
            $svc->pay($this->accountant, $inv->fresh(), '1101', now()->toDateString(), 'cash');
            $this->fail('Overpayment allowed');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('exceeds the outstanding balance', $e->getMessage());
        }
        $svc->pay($this->accountant, $inv->fresh(), '1100', now()->toDateString(), 'cash');
        $this->assertSame('paid', $inv->fresh()->payment_status);
    }

    public function test_same_supplier_invoice_cannot_be_entered_twice(): void
    {
        $po = $this->approvedPo(10, 100);
        $line = $po->lines()->sole();
        app(GoodsReceiptService::class)->receive($this->store, $po, [$line->id => 10]);
        $payload = ['supplier_invoice_no' => 'DUP-1', 'invoice_date' => now()->toDateString(), 'lines' => [$line->id => ['qty' => 5, 'unit_price' => 100]]];
        $this->actingAs($this->accountant)->post(route('invoices.store', $po), $payload)->assertRedirect();
        $this->actingAs($this->accountant)->post(route('invoices.store', $po), $payload)->assertSessionHasErrors('supplier_invoice_no');
        $this->assertSame(1, PurchaseInvoice::count());
        $this->assertSame('5.000', (string) $line->fresh()->invoiced_qty);
    }

    public function test_cancel_reopens_requisition_but_not_after_receipt(): void
    {
        $pr = $this->approvedPr(10);
        $po = $this->svc()->createOrder($this->buyer, ['purchase_requisition_id' => $pr->id, 'supplier_id' => $this->s1->id, 'store_id' => 1], [['item_id' => $this->cement->id, 'qty' => 10, 'unit_price' => 100]]);
        $this->svc()->cancelOrder($this->buyer, $po, 'Supplier out of stock');
        $this->assertSame('approved', $pr->fresh()->status);

        $po2 = $this->approvedPo(5, 100);
        app(GoodsReceiptService::class)->receive($this->store, $po2, [$po2->lines()->sole()->id => 2]);
        try {
            $this->svc()->cancelOrder($this->buyer, $po2->fresh(), 'Too late');
            $this->fail('Cancelled a PO with receipts');
        } catch (WorkflowException) {
        }
        $this->svc()->closeOrder($this->buyer, $po2->fresh(), 'Supplier cannot deliver the balance');
        $this->assertSame('closed', $po2->fresh()->status);
        $this->assertSame('0.00', $po2->fresh()->openValue());
    }

    public function test_procurement_pages_and_role_visibility(): void
    {
        $pr = $this->approvedPr(5);
        $this->svc()->addQuote($this->buyer, $pr, $this->s1->id, [$pr->lines()->sole()->id => 100]);
        $po = $this->approvedPo(5, 100);
        app(GoodsReceiptService::class)->receive($this->store, $po, [$po->lines()->sole()->id => 5]);
        $inv = app(PurchaseInvoiceService::class)->record($this->accountant, $po, ['supplier_invoice_no' => 'X', 'invoice_date' => now()->toDateString()], [$po->lines()->sole()->id => ['qty' => 5]]);

        foreach ([$this->admin, $this->overall, $this->buyer, $this->accountant] as $u) {
            $this->actingAs($u);
            foreach ([route('prs.index'), route('prs.show', $pr), route('pos.index'), route('pos.show', $po), route('suppliers.index'),
                route('suppliers.show', $this->s1), route('invoices.index'), route('invoices.show', $inv)] as $url) {
                $this->get($url)->assertOk();
            }
        }
        $this->actingAs($this->buyer)->get(route('pos.create', ['pr' => $pr->id]))->assertOk();
        $this->actingAs($this->buyer)->get(route('suppliers.create'))->assertOk();
        $this->actingAs($this->store)->get(route('pos.show', $po))->assertOk();
        $this->actingAs($this->store)->get(route('prs.create'))->assertOk();
        $this->actingAs($this->accountant)->get(route('invoices.create', $po))->assertOk();

        // Staff see nothing of procurement; storekeepers cannot see invoices or raise POs.
        $this->actingAs($this->staff)->get(route('pos.index'))->assertForbidden();
        $this->actingAs($this->staff)->get(route('pos.show', $po))->assertForbidden();
        $this->actingAs($this->store)->get(route('invoices.index'))->assertForbidden();
        $this->actingAs($this->store)->get(route('pos.create'))->assertForbidden();
        // A PM only sees POs for their own projects.
        $this->actingAs($this->otherPm)->get(route('pos.show', $po))->assertForbidden();
    }
}
