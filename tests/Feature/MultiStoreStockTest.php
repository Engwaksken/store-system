<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exceptions\WorkflowException;
use App\Models\InventoryBalance;
use App\Models\MaterialIssue;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\User;
use App\Services\IssueService;
use App\Services\ProjectCostService;
use App\Services\StockCountService;
use App\Services\StockReturnService;
use App\Services\StockService;
use App\Services\StockTransferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Phase 2: per-store balances, two-step transfers, returns, counts and idempotent posting. */
class MultiStoreStockTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    private Store $site;
    private User $siteKeeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
        $this->site = Store::create(['code' => 'SITE', 'name' => 'Site store', 'type' => 'site', 'project_id' => $this->project->id]);
        $this->siteKeeper = User::factory()->role(Role::Storekeeper)->create();
        $this->siteKeeper->stores()->attach($this->site->id);
    }

    private function transfers(): StockTransferService
    {
        return app(StockTransferService::class);
    }

    private function dispatchCement($qty): StockTransfer
    {
        return $this->transfers()->dispatch($this->store, Store::DEFAULT_ID, $this->site->id, [['item_id' => $this->cement->id, 'qty' => $qty]]);
    }

    /** Sum of ledger deltas equals the balance row, for every store/item pair. */
    private function assertLedgerReconciles(): void
    {
        foreach (InventoryBalance::all() as $b) {
            $ledger = StockMovement::where('store_id', $b->store_id)->where('item_id', $b->item_id)->get()
                ->reduce(fn ($c, $m) => bcadd($c, (string) $m->qty_delta, 3), '0.000');
            $this->assertSame((string) $b->on_hand, $ledger, "Ledger mismatch store {$b->store_id} item {$b->item_id}");
            $last = StockMovement::where('store_id', $b->store_id)->where('item_id', $b->item_id)->latest('id')->first();
            if ($last) {
                $this->assertSame((string) $b->on_hand, (string) $last->balance_after);
            }
        }
    }

    public function test_receipt_goes_to_the_chosen_store_only(): void
    {
        app(StockService::class)->receive($this->siteKeeper, ['type' => 'purchase', 'store_id' => $this->site->id, 'received_on' => now()->toDateString(), 'supplier_name' => 'S'],
            [['item_id' => $this->cement->id, 'qty' => 7, 'unit_cost' => 500]]);
        $this->assertSame('7.000', $this->cement->fresh()->onHand($this->site->id));
        $this->assertSame('0.000', $this->cement->fresh()->onHand(Store::DEFAULT_ID));
        $this->assertSame($this->site->id, StockReceipt::sole()->store_id);
    }

    public function test_storekeeper_cannot_receive_into_or_adjust_an_unassigned_store(): void
    {
        $this->expectException(AuthorizationException::class);
        app(StockService::class)->receive($this->siteKeeper, ['type' => 'purchase', 'store_id' => Store::DEFAULT_ID, 'received_on' => now()->toDateString(), 'supplier_name' => 'S'],
            [['item_id' => $this->cement->id, 'qty' => 1, 'unit_cost' => 1]]);
    }

    public function test_short_receipt_needs_reason_and_ledger_reconciles(): void
    {
        $this->receive($this->cement, 20);
        $t = $this->dispatchCement(10);
        $line = $t->lines()->sole();

        try {
            $this->transfers()->receive($this->siteKeeper, $t, [$line->id => 8]);
            $this->fail('Shortfall without reason accepted');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('shortfall', $e->getMessage());
        }
        $this->transfers()->receive($this->siteKeeper, $t, [$line->id => 8], [$line->id => '2 bags burst']);

        $this->assertSame('10.000', $this->cement->fresh()->onHand(Store::DEFAULT_ID));
        $this->assertSame('8.000', $this->cement->fresh()->onHand($this->site->id));
        $line->refresh();
        $this->assertSame('2.000', $line->shortfall());
        $this->assertSame((string) $line->qty, bcadd((string) $line->received_qty, $line->shortfall(), 3), 'dispatched = received + shortfall');
        $this->assertLedgerReconciles();
    }

    public function test_received_transfer_cannot_be_received_again_or_cancelled(): void
    {
        $this->receive($this->cement, 5);
        $t = $this->dispatchCement(5);
        $line = $t->lines()->sole();
        $this->transfers()->receive($this->siteKeeper, $t, [$line->id => 5]);

        $this->expectException(WorkflowException::class);
        $this->transfers()->cancel($this->store, $t, 'Changed mind');
    }

    public function test_cancel_returns_stock_to_source(): void
    {
        $this->receive($this->cement, 5);
        $t = $this->dispatchCement(3);
        $this->assertSame('2.000', $this->cement->fresh()->onHand(Store::DEFAULT_ID));
        $this->transfers()->cancel($this->store, $t, 'Truck broke down');

        $this->assertSame('cancelled', $t->fresh()->status);
        $this->assertSame('5.000', $this->cement->fresh()->onHand(Store::DEFAULT_ID));
        $this->assertSame('0.000', $this->cement->fresh()->onHand($this->site->id));
        $this->assertLedgerReconciles();
    }

    public function test_site_store_cannot_issue_to_another_project_and_unassigned_keeper_cannot_issue(): void
    {
        $this->receive($this->cement, 10);
        $t = $this->dispatchCement(10);
        $this->transfers()->receive($this->siteKeeper, $t, [$t->lines()->sole()->id => 10]);

        $req = $this->approvedRequest(4);
        $line = $req->lines()->sole();
        // The main storekeeper is not assigned to the site store.
        try {
            app(IssueService::class)->issue($this->store, $req, [$line->id => 4], 'X', null, $this->site->id);
            $this->fail('Unassigned storekeeper issued');
        } catch (AuthorizationException) {
        }
        // Site keeper can issue to its own project.
        $issue = app(IssueService::class)->issue($this->siteKeeper, $req, [$line->id => 4], 'Brian', null, $this->site->id);
        $this->assertSame($this->site->id, $issue->store_id);
        $this->assertSame('6.000', $this->cement->fresh()->onHand($this->site->id));

        // ...but not to a different project.
        $other = \App\Models\Project::where('code', 'P-2')->sole();
        $other->members()->attach($this->staff->id);
        $r2 = $this->wf()->createDraft($this->staff, $other, 'x', null, [['item_id' => $this->cement->id, 'requested_qty' => 1]]);
        $this->wf()->submit($this->staff, $r2);
        $this->wf()->pmDecide($this->otherPm, $r2, 'approved', null);
        $this->wf()->overallDecide($this->overall, $r2, 'approved', null);
        $this->expectException(WorkflowException::class);
        app(IssueService::class)->issue($this->siteKeeper, $r2->fresh(), [$r2->lines()->sole()->id => 1], 'X', null, $this->site->id);
    }

    public function test_duplicate_issue_post_with_same_key_does_not_double_post(): void
    {
        $this->receive($this->cement, 20);
        $req = $this->approvedRequest(10);
        $lineId = $req->lines()->sole()->id;
        $payload = ['qty' => [$lineId => 3], 'recipient_name' => 'Brian', 'store_id' => Store::DEFAULT_ID, 'idempotency_key' => 'abc-123'];

        $this->actingAs($this->store)->post(route('issues.store', $req), $payload)->assertRedirect();
        $this->actingAs($this->store)->post(route('issues.store', $req), $payload)->assertRedirect()->assertSessionHas('status', fn ($m) => str_contains($m, 'already'));

        $this->assertSame(1, MaterialIssue::count());
        $this->assertSame('17.000', $this->onHand($this->cement));
        $this->assertSame('3.000', (string) $req->lines()->sole()->issued_qty);
    }

    public function test_duplicate_receipt_post_with_same_key_does_not_double_post(): void
    {
        $payload = ['type' => 'purchase', 'store_id' => Store::DEFAULT_ID, 'received_on' => now()->toDateString(), 'supplier_name' => 'S',
            'idempotency_key' => 'grn-1', 'lines' => [['item_id' => $this->cement->id, 'qty' => 5, 'unit_cost' => 100]]];
        $this->actingAs($this->store)->post(route('receipts.store'), $payload)->assertRedirect();
        $this->actingAs($this->store)->post(route('receipts.store'), $payload)->assertRedirect();
        $this->assertSame(1, StockReceipt::count());
        $this->assertSame('5.000', $this->onHand($this->cement));
    }

    public function test_return_credits_project_once_and_cannot_exceed_what_is_out(): void
    {
        $this->receive($this->cement, 20, 30000);
        $req = $this->approvedRequest(10);
        $issue = app(IssueService::class)->issue($this->store, $req, [$req->lines()->sole()->id => 10], 'Brian');
        $issueLine = $issue->lines()->sole();
        $returns = app(StockReturnService::class);

        $ret = $returns->record($this->store, $issue, [$issueLine->id => 4], 'Brian', 'Surplus after casting');
        $this->assertSame('120000.00', (string) $ret->total_value);
        $this->assertSame('14.000', $this->onHand($this->cement));

        $cost = app(ProjectCostService::class)->summary(collect([$this->project]))->first();
        $this->assertSame('180000.00', $cost->materials, 'Net materials = 300,000 issued − 120,000 returned');

        try {
            $returns->record($this->store, $issue, [$issueLine->id => 7], 'Brian', 'Too many');
            $this->fail('Returned more than was out');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('only 6.000', $e->getMessage());
        }
        $this->assertSame('14.000', $this->onHand($this->cement));
        $this->assertLedgerReconciles();
    }

    public function test_staff_cannot_record_returns(): void
    {
        $this->receive($this->cement, 5);
        $req = $this->approvedRequest(2);
        $issue = app(IssueService::class)->issue($this->store, $req, [$req->lines()->sole()->id => 2], 'Brian');
        $this->actingAs($this->staff)->get(route('returns.create', $issue))->assertForbidden();
        $this->actingAs($this->store)->get(route('returns.create', $issue))->assertOk();
    }

    public function test_count_variance_posts_only_after_independent_approval_with_reason(): void
    {
        $this->receive($this->cement, 10);
        $counts = app(StockCountService::class);
        $count = $counts->create($this->store, Store::DEFAULT_ID);
        $line = $count->lines()->where('item_id', $this->cement->id)->sole();
        $this->assertSame('10.000', (string) $line->system_qty);

        $entries = $count->lines()->pluck('system_qty', 'id')->map(fn ($q) => ['counted_qty' => $q])->all();
        $entries[$line->id] = ['counted_qty' => '8'];
        $counts->record($this->store, $count, $entries);
        try {
            $counts->submit($this->store, $count);
            $this->fail('Variance without reason submitted');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }
        $entries[$line->id] = ['counted_qty' => '8', 'reason' => 'Two bags missing'];
        $counts->record($this->store, $count, $entries);
        $counts->submit($this->store, $count);
        $this->assertSame('10.000', $this->onHand($this->cement), 'Nothing posts on submit');

        // The storekeeper (counter) and a PM cannot approve.
        $this->actingAs($this->store)->post(route('counts.decide', $count), ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->pm)->post(route('counts.decide', $count), ['decision' => 'approved'])->assertForbidden();

        $this->actingAs($this->overall)->post(route('counts.decide', $count), ['decision' => 'approved'])->assertRedirect();
        $this->assertSame('approved', $count->fresh()->status);
        $this->assertSame('8.000', $this->onHand($this->cement));
        $adj = StockAdjustment::sole();
        $this->assertSame($count->id, $adj->stock_count_id);
        $this->assertStringContainsString('Two bags missing', $adj->reason);
        $this->assertLedgerReconciles();
    }

    public function test_rejected_count_posts_nothing_and_requires_reason(): void
    {
        $this->receive($this->cement, 10);
        $counts = app(StockCountService::class);
        $count = $counts->create($this->store, Store::DEFAULT_ID);
        $counts->record($this->store, $count, $count->lines()->get()->mapWithKeys(fn ($l) => [$l->id => ['counted_qty' => 0, 'reason' => 'Not found']])->all());
        $counts->submit($this->store, $count);

        $this->actingAs($this->admin)->post(route('counts.decide', $count), ['decision' => 'rejected'])->assertSessionHasErrors('reason');
        $this->actingAs($this->admin)->post(route('counts.decide', $count), ['decision' => 'rejected', 'reason' => 'Recount with witness'])->assertRedirect();
        $this->assertSame('rejected', $count->fresh()->status);
        $this->assertSame('10.000', $this->onHand($this->cement));
        $this->assertSame(0, StockAdjustment::count());
    }

    public function test_transfer_and_return_reconcile_across_stores(): void
    {
        $this->receive($this->cement, 50, 1000);
        $t = $this->dispatchCement(20);
        $this->transfers()->receive($this->siteKeeper, $t, [$t->lines()->sole()->id => 20]);
        $req = $this->approvedRequest(15);
        $issue = app(IssueService::class)->issue($this->siteKeeper, $req, [$req->lines()->sole()->id => 15], 'Brian', null, $this->site->id);
        app(StockReturnService::class)->record($this->siteKeeper, $issue, [$issue->lines()->sole()->id => 5], 'Brian', 'Leftover');
        app(StockService::class)->adjust($this->store, $this->cement->id, '-1', 'Damaged', Store::DEFAULT_ID);

        $this->assertSame('29.000', $this->cement->fresh()->onHand(Store::DEFAULT_ID));
        $this->assertSame('10.000', $this->cement->fresh()->onHand($this->site->id));
        $this->assertSame('39.000', $this->onHand($this->cement), '50 in − 15 issued + 5 returned − 1 damaged');
        $this->assertLedgerReconciles();
    }

    public function test_store_pages_render(): void
    {
        $this->receive($this->cement, 10);
        $t = $this->dispatchCement(2);
        $count = app(StockCountService::class)->create($this->store, Store::DEFAULT_ID);
        $req = $this->approvedRequest(2);
        $issue = app(IssueService::class)->issue($this->store, $req, [$req->lines()->sole()->id => 2], 'Brian');
        $ret = app(StockReturnService::class)->record($this->store, $issue, [$issue->lines()->sole()->id => 1], 'Brian', 'Leftover');

        foreach ([$this->admin, $this->store, $this->siteKeeper, $this->overall] as $u) {
            $this->actingAs($u);
            foreach ([route('stores.index'), route('stores.show', $this->site), route('transfers.index'), route('transfers.show', $t),
                route('counts.index'), route('counts.show', $count), route('returns.index'), route('returns.show', $ret),
                route('issues.show', $issue), route('items.show', $this->cement), route('reports.movements'), route('reports.stock', ['store_id' => 1])] as $url) {
                $this->get($url)->assertOk();
            }
        }
        $this->actingAs($this->store)->get(route('issues.create', $this->approvedRequest(1)))->assertOk();
        $this->actingAs($this->store)->get(route('returns.create', $issue))->assertOk();
        $this->actingAs($this->store)->get(route('counts.create'))->assertOk();
        $this->actingAs($this->siteKeeper)->get(route('transfers.show', $t))->assertOk()->assertSee('Confirm receipt');
    }
}
