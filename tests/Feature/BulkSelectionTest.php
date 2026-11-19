<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CostCode;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\PurchaseRequisition;
use App\Models\StockCount;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\WorkflowAlert;
use App\Services\PurchasingService;
use App\Services\StockCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Multiple selection on list pages: only authorised users see it, and records still in use are skipped, never deleted. */
class BulkSelectionTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures();
    }

    public function test_only_authorised_users_see_the_selection_column(): void
    {
        $pages = ['projects.index', 'stores.index', 'suppliers.index', 'cost-codes.index', 'users.index', 'feedback.index'];
        foreach ($pages as $page) {
            $this->actingAs($this->admin)->get(route($page))->assertOk()->assertSee('data-check-all', false);
        }
        $this->actingAs($this->staff)->get(route('projects.index'))->assertOk()->assertDontSee('data-check-all', false);
        $this->actingAs($this->store)->get(route('stores.index'))->assertOk()->assertDontSee('data-check-all', false);
        $this->actingAs($this->staff)->get(route('notifications.index'))->assertOk()->assertSee('data-check-all', false);

        $this->actingAs($this->staff)->post(route('projects.bulk'), ['ids' => [$this->project->id]])->assertForbidden();
        $this->actingAs($this->store)->post(route('stores.bulk'), ['ids' => [Store::DEFAULT_ID]])->assertForbidden();
        $this->actingAs($this->staff)->post(route('feedback.bulk'), ['ids' => [1]])->assertForbidden();
        $this->actingAs($this->pm)->post(route('users.bulk'), ['action' => 'deactivate', 'ids' => [$this->staff->id]])->assertForbidden();
    }

    public function test_projects_in_use_are_skipped_and_unused_ones_deleted(): void
    {
        $this->submittedRequest(2); // P-1 now has a request
        $empty = Project::create(['code' => 'EMPTY', 'name' => 'Never used', 'status' => 'planned', 'budget' => 0]);
        $empty->members()->attach($this->staff->id);

        $this->actingAs($this->admin)->post(route('projects.bulk'), ['ids' => [$this->project->id, $empty->id]])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '1 project(s) deleted (EMPTY)'))
            ->assertSessionHasErrors(['bulk' => 'Skipped 1: P-1: has material requests; set it to Cancelled or Completed instead']);
        $this->assertNull(Project::find($empty->id));
        $this->assertNotNull($this->project->fresh());
    }

    public function test_stores_main_and_stocked_ones_are_kept(): void
    {
        $stocked = Store::create(['code' => 'S1', 'name' => 'Stocked', 'type' => 'central']);
        $this->store->stores()->attach($stocked->id);
        app(\App\Services\StockService::class)->receive($this->admin, ['type' => 'purchase', 'store_id' => $stocked->id, 'received_on' => now()->toDateString(), 'supplier_name' => 'S'],
            [['item_id' => $this->cement->id, 'qty' => 5, 'unit_cost' => 10]]);
        $empty = Store::create(['code' => 'S2', 'name' => 'Empty', 'type' => 'central']);
        $empty->users()->attach($this->store->id);

        $this->actingAs($this->admin)->post(route('stores.bulk'), ['ids' => [Store::DEFAULT_ID, $stocked->id, $empty->id]])
            ->assertSessionHasErrors(['bulk' => 'Skipped 2: MAIN: the main store cannot be deleted; S1: has stock history; deactivate it instead']);
        $this->assertNull(Store::find($empty->id));
        $this->assertNotNull(Store::find(Store::DEFAULT_ID));
        $this->assertNotNull(Store::find($stocked->id));
    }

    public function test_master_data_used_elsewhere_is_refused_by_the_database_not_deleted(): void
    {
        $used = CostCode::create(['code' => 'CC-1', 'name' => 'Concrete', 'category' => 'materials']);
        $free = CostCode::create(['code' => 'CC-2', 'name' => 'Spare', 'category' => 'materials']);
        ProjectBudget::create(['project_id' => $this->project->id, 'cost_code_id' => $used->id, 'amount' => 100]);
        $supplier = Supplier::create(['code' => 'SUP-1', 'name' => 'Unused supplier']);

        $this->actingAs($this->admin)->post(route('cost-codes.bulk'), ['ids' => [$used->id, $free->id]])
            ->assertSessionHasErrors(['bulk' => 'Skipped 1: CC-1: in use on documents or transactions; deactivate it instead']);
        $this->assertNotNull($used->fresh());
        $this->assertNull(CostCode::find($free->id));

        $this->post(route('suppliers.bulk'), ['ids' => [$supplier->id]])->assertSessionHasNoErrors();
        $this->assertNull(Supplier::find($supplier->id));
    }

    public function test_draft_counts_and_requisitions_are_deleted_by_their_owner_only(): void
    {
        $count = app(StockCountService::class)->create($this->store, Store::DEFAULT_ID, [$this->cement->id]);
        $this->actingAs($this->store)->post(route('counts.bulk'), ['ids' => [$count->id]])->assertSessionHasNoErrors();
        $this->assertNull(StockCount::find($count->id));

        $svc = app(PurchasingService::class);
        $draft = $svc->createRequisition($this->store, ['store_id' => Store::DEFAULT_ID, 'purpose' => 'x'], [['item_id' => $this->cement->id, 'qty' => 1]]);
        $submitted = $svc->createRequisition($this->store, ['store_id' => Store::DEFAULT_ID, 'purpose' => 'y'], [['item_id' => $this->cement->id, 'qty' => 1]]);
        $svc->submitRequisition($this->store, $submitted);

        $this->actingAs($this->store)->post(route('prs.bulk'), ['action' => 'delete', 'ids' => [$draft->id, $submitted->id]])
            ->assertSessionHasErrors('bulk');
        $this->assertNull(PurchaseRequisition::find($draft->id));
        $this->assertNotNull($submitted->fresh());
    }

    public function test_notifications_are_deleted_for_their_owner_only(): void
    {
        $this->staff->notify(new WorkflowAlert('Mine', 'x', '/', 'Open'));
        $this->pm->notify(new WorkflowAlert('Theirs', 'x', '/', 'Open'));
        $ids = \Illuminate\Notifications\DatabaseNotification::pluck('id')->all();

        $this->actingAs($this->staff)->post(route('notifications.bulk-delete'), ['ids' => $ids])->assertSessionHas('status', '1 notification(s) deleted.');
        $this->assertSame(0, $this->staff->notifications()->count());
        $this->assertSame(1, $this->pm->notifications()->count());
    }

    public function test_feedback_is_deleted_by_the_admin(): void
    {
        $f = Feedback::create(['user_id' => $this->staff->id, 'category' => 'bug', 'subject' => 'x', 'message' => 'y', 'status' => 'new']);
        $this->actingAs($this->admin)->post(route('feedback.bulk'), ['ids' => [$f->id]])->assertSessionHasNoErrors();
        $this->assertNull(Feedback::find($f->id));
    }

    public function test_users_are_deactivated_and_activated_never_deleted(): void
    {
        $this->staff->createToken('phone');

        $this->actingAs($this->admin)->post(route('users.bulk'), ['action' => 'deactivate', 'ids' => [$this->staff->id, $this->admin->id]])
            ->assertSessionHasErrors(['bulk' => "Skipped 1: {$this->admin->name}: you cannot deactivate your own account"]);
        $this->assertFalse($this->staff->fresh()->is_active);
        $this->assertSame(0, $this->staff->tokens()->count());
        $this->assertTrue($this->admin->fresh()->is_active);

        $this->post(route('users.bulk'), ['action' => 'activate', 'ids' => [$this->staff->id]])->assertSessionHasNoErrors();
        $this->assertTrue($this->staff->fresh()->is_active);
        $this->assertSame(User::count(), User::withoutGlobalScopes()->count());
    }

    public function test_the_last_active_admin_cannot_be_deactivated(): void
    {
        $other = User::factory()->role(Role::Admin)->create();
        $other->forceFill(['is_active' => false])->save();
        $second = User::factory()->role(Role::Admin)->create();

        $this->actingAs($second)->post(route('users.bulk'), ['action' => 'deactivate', 'ids' => [$this->admin->id]])->assertSessionHasNoErrors();
        $this->actingAs($this->admin->fresh())->post(route('users.bulk'), ['action' => 'deactivate', 'ids' => [$second->id]]);
        $this->assertTrue($second->fresh()->is_active, 'last active admin stays active');
    }
}
