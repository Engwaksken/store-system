<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\ExpenseCategory;
use App\Models\InventoryCategory;
use App\Models\InventoryUnit;
use App\Models\MaterialRequest;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchasingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Multi-select bulk actions on request / PR / PO tables, and the tabbed catalog setup (categories, units, expense categories). */
class BulkActionsAndSetupTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    // ---------------------------------------------------------------- material requests

    public function test_admin_bulk_approves_requests_through_both_steps(): void
    {
        $a = $this->submittedRequest(5);
        $b = $this->submittedRequest(3, $this->steel);

        $this->actingAs($this->admin)->post(route('requests.bulk'), ['action' => 'approve', 'ids' => [$a->id, $b->id]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::PmApproved, $a->fresh()->status);
        $this->assertSame(RequestStatus::PmApproved, $b->fresh()->status);
        $this->assertSame('5.000', (string) $a->lines()->first()->pm_approved_qty);

        $this->actingAs($this->admin)->post(route('requests.bulk'), ['action' => 'approve', 'ids' => [$a->id, $b->id]])->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::OverallApproved, $a->fresh()->status);
        $this->assertSame(RequestStatus::OverallApproved, $b->fresh()->status);
    }

    public function test_bulk_approve_skips_requests_not_waiting_for_the_user_and_stale_versions(): void
    {
        $mine = $this->submittedRequest(5);
        $draft = $this->wf()->createDraft($this->staff, $this->project, 'Later', null, [['item_id' => $this->cement->id, 'requested_qty' => 1]]);

        $this->actingAs($this->pm)->post(route('requests.bulk'), ['action' => 'approve', 'ids' => [$mine->id, $draft->id], 'versions' => [$mine->id => 9]])
            ->assertSessionHasErrors('bulk');
        $this->assertSame(RequestStatus::Submitted, $mine->fresh()->status, 'Stale version refused');
        $this->assertSame(RequestStatus::Draft, $draft->fresh()->status);

        $this->actingAs($this->pm)->post(route('requests.bulk'), ['action' => 'reject', 'ids' => [$mine->id], 'reason' => 'Too much', 'versions' => [$mine->id => 1]])
            ->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::PmRejected, $mine->fresh()->status);
    }

    public function test_bulk_delete_removes_only_undecided_drafts_and_cancel_handles_submitted(): void
    {
        $draft = $this->wf()->createDraft($this->staff, $this->project, 'Draft', null, [['item_id' => $this->cement->id, 'requested_qty' => 1]]);
        $submitted = $this->submittedRequest(2);

        $this->actingAs($this->staff)->post(route('requests.bulk'), ['action' => 'delete', 'ids' => [$draft->id, $submitted->id]])
            ->assertSessionHasErrors('bulk');
        $this->assertNull(MaterialRequest::find($draft->id));
        $this->assertNotNull(MaterialRequest::find($submitted->id));
        $this->assertTrue(ActivityLog::where('action', 'request.deleted')->exists());

        $this->actingAs($this->staff)->post(route('requests.bulk'), ['action' => 'cancel', 'ids' => [$submitted->id]])->assertSessionHasNoErrors();
        $this->assertSame(RequestStatus::Cancelled, $submitted->fresh()->status);
    }

    public function test_other_users_cannot_bulk_act_on_requests_they_cannot_see(): void
    {
        $req = $this->wf()->createDraft($this->staff, $this->project, 'Draft', null, [['item_id' => $this->cement->id, 'requested_qty' => 1]]);
        $this->actingAs($this->outsider)->post(route('requests.bulk'), ['action' => 'delete', 'ids' => [$req->id]]);
        $this->assertNotNull(MaterialRequest::find($req->id));
    }

    // ---------------------------------------------------------------- purchasing

    public function test_bulk_approve_requisitions_and_purchase_orders(): void
    {
        $svc = app(PurchasingService::class);
        $buyer = User::factory()->role(Role::Procurement)->create();
        $supplier = Supplier::create(['code' => 'S1', 'name' => 'Hardware']);

        $pr = $svc->createRequisition($this->store, ['store_id' => Store::DEFAULT_ID, 'purpose' => 'Restock'], [['item_id' => $this->cement->id, 'qty' => 10, 'est_unit_cost' => 100]]);
        $svc->submitRequisition($this->store, $pr);
        $this->actingAs($this->overall)->post(route('prs.bulk'), ['action' => 'approve', 'ids' => [$pr->id]])->assertSessionHasNoErrors();
        $this->assertSame('approved', $pr->fresh()->status);

        $po = $svc->createOrder($buyer, ['supplier_id' => $supplier->id, 'store_id' => Store::DEFAULT_ID], [['item_id' => $this->cement->id, 'qty' => 1, 'unit_price' => 100]]);
        $this->actingAs($this->admin)->post(route('pos.bulk'), ['action' => 'approve', 'ids' => [$po->id], 'versions' => [$po->id => $po->lock_version + 3]])
            ->assertSessionHasErrors('bulk');
        $this->assertSame('pending_approval', $po->fresh()->status, 'Stale version refused');
        $this->actingAs($this->admin)->post(route('pos.bulk'), ['action' => 'approve', 'ids' => [$po->id], 'versions' => [$po->id => $po->fresh()->lock_version]])
            ->assertSessionHasNoErrors();
        $this->assertSame('approved', PurchaseOrder::find($po->id)->status);

        // The buyer cannot approve their own PO; the skip is reported.
        $own = $svc->createOrder($buyer, ['supplier_id' => $supplier->id, 'store_id' => Store::DEFAULT_ID], [['item_id' => $this->cement->id, 'qty' => 1, 'unit_price' => 100]]);
        $this->actingAs($buyer)->post(route('pos.bulk'), ['action' => 'approve', 'ids' => [$own->id]])->assertSessionHasErrors('bulk');
        $this->assertSame('pending_approval', $own->fresh()->status);
    }

    // ---------------------------------------------------------------- catalog setup

    public function test_setup_page_shows_tabs_and_tables(): void
    {
        ExpenseCategory::create(['name' => 'Transport', 'is_active' => true]);
        $this->actingAs($this->admin)->get(route('catalog.setup'))->assertOk()
            ->assertSee('data-tabs="catalog-setup"', false)->assertSee('Item categories')->assertSee('Units of measure')->assertSee('Transport');
        // Storekeepers manage the catalogue but not expense categories.
        $this->actingAs($this->store)->get(route('catalog.setup'))->assertOk()->assertDontSee('Transport');
    }

    public function test_edit_and_delete_item_categories_and_units_unless_in_use(): void
    {
        $used = InventoryCategory::where('name', 'Cement')->first();
        $spare = InventoryCategory::create(['name' => 'Spare']);
        $unit = InventoryUnit::create(['name' => 'Litre', 'abbreviation' => 'L']);

        $this->actingAs($this->store)->put(route('catalog.categories.update', $spare), ['name' => 'Paint'])->assertRedirect();
        $this->assertSame('Paint', $spare->fresh()->name);
        $this->actingAs($this->store)->put(route('catalog.units.update', $unit), ['name' => 'Litre', 'abbreviation' => 'ltr'])->assertRedirect();
        $this->assertSame('ltr', $unit->fresh()->abbreviation);

        $this->actingAs($this->store)->delete(route('catalog.setup.destroy', ['categories', $used->id]))->assertSessionHasErrors('setup');
        $this->assertNotNull($used->fresh());

        $this->actingAs($this->store)->post(route('catalog.setup.bulk-delete', 'categories'), ['ids' => [$used->id, $spare->id]])->assertSessionHasErrors('setup');
        $this->assertNull(InventoryCategory::find($spare->id));
        $this->assertNotNull($used->fresh());

        $this->actingAs($this->store)->delete(route('catalog.setup.destroy', ['units', $unit->id]))->assertSessionHasNoErrors();
        $this->assertNull(InventoryUnit::find($unit->id));

        $this->actingAs($this->staff)->post(route('catalog.setup.bulk-delete', 'units'), ['ids' => [1]])->assertForbidden();
    }

    public function test_expense_categories_edit_deactivate_and_delete_only_when_unused(): void
    {
        $c = ExpenseCategory::create(['name' => 'Fuel', 'is_active' => true]);
        $this->actingAs($this->admin)->put(route('catalog.expense-categories.update', $c), ['name' => 'Fuel & oil'])->assertRedirect();
        $this->assertSame('Fuel & oil', $c->fresh()->name);
        $this->assertFalse($c->fresh()->is_active, 'Unticked Active deactivates');

        $this->actingAs($this->store)->delete(route('catalog.setup.destroy', ['expense-categories', $c->id]))->assertForbidden();
        $this->actingAs($this->admin)->post(route('catalog.setup.bulk-delete', 'expense-categories'), ['ids' => [$c->id]])->assertSessionHasNoErrors();
        $this->assertNull(ExpenseCategory::find($c->id));
    }

    // ---------------------------------------------------------------- filters

    public function test_store_filter_is_labelled_store(): void
    {
        $this->actingAs($this->admin)->get(route('issues.index'))->assertOk()->assertSee('All stores')->assertDontSee('All branches');
        $this->actingAs($this->admin)->get(route('requests.index', ['store_id' => Store::DEFAULT_ID]))->assertOk()->assertSee('Issued from');
    }
}
