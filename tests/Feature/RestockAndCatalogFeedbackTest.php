<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\PurchaseRequisition;
use App\Services\IssueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/**
 * User feedback round (18 Nov 2026): short issues raise a restock requisition, catalogue delete fixes,
 * bulk item delete, stock prices for the Owner/Admin and Overall Manager only, and the movement ledger.
 */
class RestockAndCatalogFeedbackTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->buildFixtures();
    }

    // ------------------------------------------------------------------ restock on a short issue

    public function test_short_issue_sends_the_balance_to_the_overall_manager_for_restocking(): void
    {
        $this->receive($this->cement, 6);
        $req = $this->approvedRequest(10); // cement min level is 5
        $line = $req->lines()->first();

        $issue = app(IssueService::class)->issue($this->store, $req, [$line->id => 6], 'Recipient');

        $this->assertSame(RequestStatus::PartiallyIssued, $req->fresh()->status);
        $pr = PurchaseRequisition::sole();
        $this->assertSame($pr->id, $issue->getRelation('restock')->id);
        $this->assertSame('submitted', $pr->status);
        $this->assertSame($req->id, $pr->material_request_id);
        $this->assertSame($this->store->id, $pr->requested_by);
        // Balance 4 is below the minimum level 5, so the larger (5) is asked for.
        $this->assertSame('5.000', (string) $pr->lines()->sole()->qty);
        $this->assertTrue($this->overall->notifications()->where('data->subject', 'like', '%'.$pr->pr_no.'%')->exists());
    }

    public function test_restock_quantity_is_the_outstanding_balance_when_it_exceeds_the_minimum(): void
    {
        $this->receive($this->cement, 2);
        $req = $this->approvedRequest(30);
        app(IssueService::class)->issue($this->store, $req, [$req->lines()->first()->id => 2], 'Recipient');

        $this->assertSame('28.000', (string) PurchaseRequisition::sole()->lines()->sole()->qty);
    }

    public function test_a_second_short_issue_does_not_ask_for_the_same_item_twice(): void
    {
        $this->receive($this->cement, 4);
        $req = $this->approvedRequest(20);
        $lineId = $req->lines()->first()->id;
        app(IssueService::class)->issue($this->store, $req, [$lineId => 2], 'A');
        app(IssueService::class)->issue($this->store, $req->fresh(), [$lineId => 2], 'B');

        $this->assertSame(1, PurchaseRequisition::count());
    }

    public function test_full_issue_raises_no_restock(): void
    {
        $this->receive($this->cement, 50);
        $req = $this->approvedRequest(10);
        app(IssueService::class)->issue($this->store, $req, [$req->lines()->first()->id => 10], 'Recipient');

        $this->assertSame(0, PurchaseRequisition::count());
    }

    public function test_storekeeper_can_request_restock_without_issuing_when_nothing_is_in_stock(): void
    {
        $req = $this->approvedRequest(10);
        $this->actingAs($this->store)->get(route('issues.create', $req))->assertOk()->assertSee('Request restock only');

        $this->actingAs($this->store)->post(route('issues.restock', $req))->assertRedirect(route('requests.show', $req));
        $pr = PurchaseRequisition::sole();
        $this->assertSame('10.000', (string) $pr->lines()->sole()->qty);
        $this->assertSame(RequestStatus::OverallApproved, $req->fresh()->status);

        $this->actingAs($this->staff)->get(route('requests.show', $req))->assertOk()->assertSee($pr->pr_no);
        $this->actingAs($this->overall)->get(route('prs.show', $pr))->assertOk()->assertSee($req->request_no);
        $this->actingAs($this->staff)->post(route('issues.restock', $req))->assertForbidden();
    }

    // ------------------------------------------------------------------ catalogue setup and items

    public function test_category_in_use_is_deleted_after_moving_its_items(): void
    {
        $from = $this->cement->category;
        $to = InventoryCategory::create(['name' => 'Binders']);
        $this->actingAs($this->admin)->get(route('catalog.setup'))->assertOk()->assertSee('category-delete-'.$from->id, false);

        $this->actingAs($this->admin)->delete(route('catalog.setup.destroy', ['categories', $from->id]), ['move_to' => $to->id])->assertSessionHasNoErrors();

        $this->assertNull(InventoryCategory::find($from->id));
        $this->assertSame($to->id, $this->cement->fresh()->category_id);
        $this->assertSame($to->id, $this->steel->fresh()->category_id);
    }

    public function test_unit_in_use_moves_to_another_unit_and_cannot_move_to_itself(): void
    {
        $from = $this->cement->unit;
        $this->actingAs($this->admin)->delete(route('catalog.setup.destroy', ['units', $from->id]), ['move_to' => $from->id])->assertSessionHasErrors('setup');
        $this->assertNotNull(InventoryUnit::find($from->id));

        $to = InventoryUnit::create(['name' => 'Kilogram', 'abbreviation' => 'kg']);
        $this->actingAs($this->admin)->delete(route('catalog.setup.destroy', ['units', $from->id]), ['move_to' => $to->id])->assertSessionHasNoErrors();
        $this->assertSame($to->id, $this->cement->fresh()->unit_id);
    }

    public function test_bulk_delete_items_deletes_unused_items_and_skips_those_with_history(): void
    {
        $unused = InventoryItem::create(['sku' => 'NEW', 'name' => 'Never used', 'category_id' => $this->cement->category_id, 'unit_id' => $this->cement->unit_id, 'min_level' => 0, 'is_active' => true]);
        $this->receive($this->cement, 5);
        $this->approvedRequest(1, $this->steel); // on a request, no stock history

        $this->actingAs($this->store)->get(route('items.index'))->assertOk()->assertSee('data-bulk-scope="items"', false)->assertSee('data-live-refresh', false);
        $this->actingAs($this->store)->post(route('items.bulk-delete'), ['ids' => [$unused->id, $this->cement->id, $this->steel->id]])
            ->assertSessionHasErrors('items');

        $this->assertNull(InventoryItem::find($unused->id));
        $this->assertNotNull(InventoryItem::find($this->cement->id));
        $this->assertNotNull(InventoryItem::find($this->steel->id));
        $this->actingAs($this->staff)->post(route('items.bulk-delete'), ['ids' => [$this->cement->id]])->assertForbidden();
    }

    // ------------------------------------------------------------------ prices and the ledger

    public function test_stock_prices_are_only_for_the_owner_and_overall_manager(): void
    {
        $this->receive($this->cement, 5, 31234);
        $receipt = \App\Models\StockReceipt::sole();
        foreach ([$this->store, $this->accountant] as $user) {
            $this->actingAs($user)->get(route('items.show', $this->cement))->assertOk()->assertDontSee('31,234');
            $this->actingAs($user)->get(route('items.index'))->assertOk()->assertDontSee('Avg cost');
            $this->actingAs($user)->get(route('reports.movements'))->assertOk()->assertDontSee('Unit cost');
        }
        $this->actingAs($this->store)->get(route('receipts.show', $receipt))->assertOk()->assertDontSee('156,170');
        foreach ([$this->admin, $this->overall] as $user) {
            $this->actingAs($user)->get(route('items.show', $this->cement))->assertOk()->assertSee('31,234');
            $this->actingAs($user)->get(route('reports.movements'))->assertOk()->assertSee('Unit cost');
        }
    }

    public function test_movement_ledger_shows_the_latest_rows_and_says_when_it_is_cut(): void
    {
        $this->receive($this->cement, 5);
        $this->actingAs($this->admin)->get(route('reports.movements'))->assertOk()->assertSee('CEM')->assertDontSee('Showing the latest');
        $this->actingAs($this->admin)->get(route('reports.movements', ['format' => 'pdf']))->assertOk();
    }

    public function test_new_request_form_separates_available_and_out_of_stock_items(): void
    {
        $this->receive($this->cement, 5);
        $this->actingAs($this->staff)->get(route('requests.create'))->assertOk()
            ->assertSee('Available in store (1)', false)->assertSee('Out of stock (will need restocking) (1)', false);
    }
}
