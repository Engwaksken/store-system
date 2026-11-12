<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Exceptions\WorkflowException;
use App\Models\MaterialIssue;
use App\Models\StockMovement;
use App\Services\IssueService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** UAT §Tests: partial issue, over-issue guards, ledger reconciliation, stock never negative. */
class IssuanceAndStockTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    private function issue($req, $qty, ?int $lineId = null)
    {
        return app(IssueService::class)->issue($this->store, $req, [($lineId ?? $req->lines()->first()->id) => $qty], 'Recipient');
    }

    public function test_receipt_increments_balance_and_writes_ledger(): void
    {
        $this->receive($this->cement, 25, 30000);
        $this->assertSame('25.000', $this->onHand($this->cement));
        $m = StockMovement::where('item_id', $this->cement->id)->sole();
        $this->assertSame('receipt', $m->movement_type);
        $this->assertSame('25.000', (string) $m->balance_after);
        $this->assertSame('30000.0000', (string) $this->cement->fresh()->avg_cost);
    }

    public function test_weighted_average_cost_on_receipts(): void
    {
        $this->receive($this->cement, 10, 30000);
        $this->receive($this->cement, 30, 34000);
        // (10*30000 + 30*34000) / 40 = 33000
        $this->assertSame('33000.0000', (string) $this->cement->fresh()->avg_cost);
    }

    public function test_approval_alone_does_not_decrement_stock(): void
    {
        $this->receive($this->cement, 50);
        $this->approvedRequest(10);
        $this->assertSame('50.000', $this->onHand($this->cement));
        $this->assertSame(0, StockMovement::where('movement_type', 'issue')->count());
    }

    /** Brief: "Approved 10 units and 6 available: issue up to 6; remainder pending; subsequent receipt allows only remaining 4, never above 10." */
    public function test_approved_10_with_6_available_scenario(): void
    {
        $this->receive($this->cement, 6);
        $req = $this->approvedRequest(10);

        try {
            $this->issue($req, 7);
            $this->fail('Issued more than on hand');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('in stock', $e->getMessage());
        }

        $this->issue($req, 6);
        $this->assertSame('0.000', $this->onHand($this->cement));
        $this->assertSame(RequestStatus::PartiallyIssued, $req->fresh()->status);
        $this->assertSame('4.000', $req->lines()->first()->outstanding());

        $this->receive($this->cement, 20);
        try {
            $this->issue($req, 5);
            $this->fail('Issued above approved quantity');
        } catch (WorkflowException $e) {
            $this->assertStringContainsString('approved quantity remains', $e->getMessage());
        }

        $this->issue($req, 4);
        $this->assertSame(RequestStatus::FullyIssued, $req->fresh()->status);
        $this->assertSame('10.000', (string) $req->lines()->first()->issued_qty);
        $this->assertSame('16.000', $this->onHand($this->cement));

        $this->expectException(WorkflowException::class);
        $this->issue($req, 1);
    }

    public function test_final_units_cannot_be_issued_twice_to_competing_requests(): void
    {
        $this->receive($this->cement, 5);
        $a = $this->approvedRequest(5);
        $b = $this->approvedRequest(5);
        $this->issue($a, 5);
        try {
            $this->issue($b, 1);
            $this->fail('Overspent stock');
        } catch (WorkflowException) {
        }
        $this->assertSame('0.000', $this->onHand($this->cement));
        $this->assertSame(1, MaterialIssue::count());
    }

    public function test_failed_multi_line_issue_rolls_back_everything(): void
    {
        $this->receive($this->cement, 10);
        $this->receive($this->steel, 1);
        $req = $this->wf()->createDraft($this->staff, $this->project, 'x', null, [
            ['item_id' => $this->cement->id, 'requested_qty' => 5], ['item_id' => $this->steel->id, 'requested_qty' => 5],
        ]);
        $this->wf()->submit($this->staff, $req);
        $this->wf()->pmDecide($this->pm, $req, 'approved', null);
        $this->wf()->overallDecide($this->overall, $req, 'approved', null);
        $lines = $req->lines()->orderBy('id')->get();

        try {
            app(IssueService::class)->issue($this->store, $req, [$lines[0]->id => 5, $lines[1]->id => 5], 'X');
            $this->fail('Should fail on steel');
        } catch (WorkflowException) {
        }
        $this->assertSame('10.000', $this->onHand($this->cement));
        $this->assertSame(0, MaterialIssue::count());
        $this->assertSame('0.000', (string) $lines[0]->fresh()->issued_qty);
    }

    public function test_ledger_and_vouchers_reconcile_with_balances(): void
    {
        $this->receive($this->cement, 40, 30000);
        $this->receive($this->steel, 20, 40000);
        $r1 = $this->approvedRequest(15);
        $r2 = $this->approvedRequest(8, $this->steel);
        $v1 = $this->issue($r1, 10);
        $this->issue($r1, 5);
        $v3 = $this->issue($r2, 8);
        app(StockService::class)->adjust($this->store, $this->cement->id, '-2', 'Two bags damaged by rain');

        foreach ([$this->cement, $this->steel] as $item) {
            $ledger = StockMovement::where('item_id', $item->id)->get()->reduce(fn ($c, $m) => bcadd($c, (string) $m->qty_delta, 3), '0');
            $this->assertSame($this->onHand($item), $ledger, "Ledger mismatch for {$item->sku}");
            $this->assertSame($this->onHand($item), (string) StockMovement::where('item_id', $item->id)->latest('id')->first()->balance_after);
        }
        $this->assertSame('23.000', $this->onHand($this->cement));

        foreach (MaterialIssue::with('lines')->get() as $v) {
            $moves = StockMovement::where('source_type', 'material_issue')->where('source_id', $v->id)->get();
            $this->assertSame($v->lines->count(), $moves->count());
            $this->assertEquals((float) $v->lines->sum('qty'), -(float) $moves->sum('qty_delta'));
            $this->assertEquals((float) $v->total_value, (float) $v->lines->sum('line_value'));
            $this->assertSame($v->project_id, $moves->first()->project_id);
        }
        $this->assertSame('300000.00', (string) $v1->total_value);
        $this->assertSame('320000.00', (string) $v3->total_value);
    }

    public function test_adjustment_cannot_make_stock_negative_and_needs_reason(): void
    {
        $this->receive($this->cement, 3);
        try {
            app(StockService::class)->adjust($this->store, $this->cement->id, '-4', 'Lost');
            $this->fail('Negative stock allowed');
        } catch (WorkflowException) {
        }
        $this->actingAs($this->store)->post(route('adjustments.store'), ['item_id' => $this->cement->id, 'qty_delta' => -1, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame('3.000', $this->onHand($this->cement));
    }

    public function test_opening_balance_only_once_per_item(): void
    {
        $this->receive($this->cement, 10, 1000, 'opening');
        $this->expectException(WorkflowException::class);
        $this->receive($this->cement, 5, 1000, 'opening');
    }

    public function test_ledger_and_vouchers_are_immutable(): void
    {
        $this->receive($this->cement, 10);
        $v = $this->issue($this->approvedRequest(2), 2);
        try {
            StockMovement::first()->update(['qty_delta' => 999]);
            $this->fail('Ledger editable');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $v->delete();
    }

    public function test_issue_via_http_creates_voucher_and_requester_can_acknowledge_once(): void
    {
        $this->receive($this->cement, 10);
        $req = $this->approvedRequest(4);
        $line = $req->lines()->first();
        $this->actingAs($this->store)->post(route('issues.store', $req), ['qty' => [$line->id => 4], 'recipient_name' => 'Site foreman'])->assertRedirect();
        $issue = MaterialIssue::sole();
        $this->assertMatchesRegularExpression('/^IV-\d{4}-\d{5}$/', $issue->issue_no);
        $this->actingAs($this->staff)->get(route('issues.show', $issue))->assertOk()->assertSee($issue->issue_no);

        $this->actingAs($this->outsider)->post(route('issues.acknowledge', $issue), ['recipient_name' => 'X'])->assertForbidden();
        $this->actingAs($this->staff)->post(route('issues.acknowledge', $issue), ['recipient_name' => 'Site foreman'])->assertRedirect();
        $this->assertDatabaseHas('issue_acknowledgments', ['material_issue_id' => $issue->id, 'acknowledged_by' => $this->staff->id]);
        $this->actingAs($this->staff)->post(route('issues.acknowledge', $issue), [])->assertForbidden();
    }

    public function test_document_numbers_are_sequential_and_unique(): void
    {
        $this->receive($this->cement, 100);
        $nos = collect(range(1, 3))->map(fn () => $this->issue($this->approvedRequest(1), 1)->issue_no);
        $this->assertSame($nos->unique()->count(), 3);
        $this->assertStringEndsWith('00003', $nos->last());
    }
}
