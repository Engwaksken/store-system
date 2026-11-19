<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\InventoryItem;
use App\Models\Project;
use App\Models\Store;
use App\Models\User;
use App\Notifications\WorkflowAlert;
use App\Services\IssueService;
use App\Services\PurchasingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\BuildsFixtures;
use Tests\TestCase;

/**
 * Revised roles (docs/ROLE_PERMISSION_MATRIX.md):
 * - storekeepers see and issue only to their assigned projects (plus a site store's project);
 * - procurement sees every project;
 * - storekeepers see requisitions for the stores they run;
 * - storekeepers and procurement add / edit items; categories, units and deleting are the Owner/Admin's.
 */
class RolePermissionsTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    private Project $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixtures(); // $this->store (storekeeper) serves P-1 only
        $this->other = Project::where('code', 'P-2')->sole();
        $this->other->members()->attach($this->staff->id);
    }

    private function approvedOn(Project $project, $qty = 2)
    {
        $r = $this->wf()->createDraft($this->staff, $project, 'x', null, [['item_id' => $this->cement->id, 'requested_qty' => $qty]]);
        $this->wf()->submit($this->staff, $r);
        $this->wf()->pmDecide($project->id === $this->project->id ? $this->pm : $this->otherPm, $r, 'approved', null);
        $this->wf()->overallDecide($this->overall, $r, 'approved', null);

        return $r->fresh();
    }

    // ---------------------------------------------------------------- storekeeper project scope

    public function test_storekeeper_sees_and_issues_only_for_assigned_projects(): void
    {
        $this->receive($this->cement, 20);
        $mine = $this->approvedOn($this->project);
        $theirs = $this->approvedOn($this->other);

        $this->actingAs($this->store)->get(route('projects.index'))->assertOk()->assertSee('P-1')->assertDontSee('Other project');
        $this->get(route('projects.show', $this->other))->assertForbidden();
        $this->get(route('issues.index'))->assertOk()->assertSee($mine->request_no)->assertDontSee($theirs->request_no);
        $this->get(route('requests.show', $theirs))->assertForbidden();
        $this->get(route('issues.create', $theirs))->assertForbidden();

        $this->expectException(AuthorizationException::class);
        app(IssueService::class)->issue($this->store, $theirs, [$theirs->lines()->sole()->id => 1], 'X');
    }

    public function test_a_site_store_brings_its_project_and_assigning_a_project_opens_it(): void
    {
        $keeper = User::factory()->role(Role::Storekeeper)->create();
        $site = Store::create(['code' => 'S2', 'name' => 'P-2 site', 'type' => 'site', 'project_id' => $this->other->id]);
        $keeper->stores()->attach($site->id);

        $this->assertSame([$this->other->id], Project::visibleTo($keeper)->pluck('id')->all());

        $keeper->projects()->attach($this->project->id);
        $this->assertEqualsCanonicalizing([$this->project->id, $this->other->id], Project::visibleTo($keeper)->pluck('id')->all());
    }

    public function test_issue_queue_api_and_ready_notification_follow_the_assignment(): void
    {
        Notification::fake();
        $outsider = User::factory()->role(Role::Storekeeper)->create(); // serves no project
        $mine = $this->approvedOn($this->project);
        $this->approvedOn($this->other);

        Notification::assertSentTo($this->store, WorkflowAlert::class);
        Notification::assertNotSentTo($outsider, WorkflowAlert::class);

        $token = $this->store->createToken('t')->plainTextToken;
        $this->getJson('/api/v1/issues/queue', ['Authorization' => "Bearer {$token}"])->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->getJson('/api/v1/dashboard', ['Authorization' => "Bearer {$token}"])->assertOk();
    }

    public function test_project_form_lists_storekeepers_and_keeps_members_it_does_not_show(): void
    {
        $accountant = $this->accountant;
        $this->project->members()->attach($accountant->id); // ticked on the user record

        $this->actingAs($this->admin)->get(route('projects.edit', $this->project))->assertOk()->assertSee($this->store->name);
        $this->put(route('projects.update', $this->project), [
            'code' => 'P-1', 'name' => 'Test project', 'status' => 'active', 'budget' => 1000000, 'manager_id' => $this->pm->id,
            'member_ids' => [$this->staff->id],
        ])->assertRedirect();

        $members = $this->project->members()->pluck('users.id');
        $this->assertTrue($members->contains($accountant->id), 'members not on the form are kept');
        $this->assertFalse($members->contains($this->store->id), 'unticking a storekeeper removes them');
    }

    public function test_migration_ticks_existing_storekeepers_on_every_project(): void
    {
        $fresh = User::factory()->role(Role::Storekeeper)->create();
        $assigned = $this->store; // already on P-1: left alone
        (require database_path('migrations/2026_11_20_000200_assign_storekeepers_to_projects.php'))->up();

        $this->assertSame(Project::count(), $fresh->projects()->count());
        $this->assertSame(1, $assigned->projects()->count());
    }

    // ---------------------------------------------------------------- procurement

    public function test_procurement_sees_every_project_and_raises_requisitions_for_any(): void
    {
        $buyer = User::factory()->role(Role::Procurement)->create();
        $this->assertSame(Project::count(), Project::visibleTo($buyer)->count());

        $pr = app(PurchasingService::class)->createRequisition($buyer, ['store_id' => Store::DEFAULT_ID, 'project_id' => $this->other->id, 'purpose' => 'x'],
            [['item_id' => $this->cement->id, 'qty' => 5, 'est_unit_cost' => 10]]);
        $this->assertSame($this->other->id, $pr->project_id);
    }

    // ---------------------------------------------------------------- requisitions for storekeepers

    public function test_storekeeper_sees_requisitions_for_their_stores_and_orders_only_for_them(): void
    {
        $buyer = User::factory()->role(Role::Procurement)->create();
        $elsewhere = Store::create(['code' => 'FAR', 'name' => 'Far store', 'type' => 'central']);
        $svc = app(PurchasingService::class);
        $forMyStore = $svc->createRequisition($buyer, ['store_id' => Store::DEFAULT_ID, 'purpose' => 'x'], [['item_id' => $this->cement->id, 'qty' => 5]]);
        $forFar = $svc->createRequisition($buyer, ['store_id' => $elsewhere->id, 'purpose' => 'y'], [['item_id' => $this->cement->id, 'qty' => 5]]);

        $this->actingAs($this->store)->get(route('prs.show', $forMyStore))->assertOk();
        $this->get(route('prs.show', $forFar))->assertForbidden();
        $this->get(route('prs.create'))->assertOk()->assertDontSee('Far store');

        $this->expectException(AuthorizationException::class);
        $svc->createRequisition($this->store, ['store_id' => $elsewhere->id, 'purpose' => 'z'], [['item_id' => $this->cement->id, 'qty' => 1]]);
    }

    // ---------------------------------------------------------------- catalog

    public function test_storekeeper_and_procurement_manage_items_but_not_categories_units_or_deletes(): void
    {
        $buyer = User::factory()->role(Role::Procurement)->create();
        $item = fn (string $sku) => ['sku' => $sku, 'name' => "Item {$sku}", 'category_id' => $this->cement->category_id, 'unit_id' => $this->cement->unit_id, 'min_level' => 1, 'is_active' => 1];

        foreach ([$this->store, $buyer] as $i => $u) {
            $this->actingAs($u)->get(route('items.create'))->assertOk();
            $this->post(route('items.store'), $item("NEW{$i}"))->assertRedirect();
            $this->put(route('items.update', $this->cement), [...$item($this->cement->sku), 'name' => "Cement {$i}"])->assertRedirect();
            $this->get(route('catalog.setup'))->assertForbidden();
            $this->post(route('catalog.categories.store'), ['name' => "Cat {$i}"])->assertForbidden();
            $this->post(route('items.bulk-delete'), ['ids' => [$this->steel->id]])->assertForbidden();
            $this->get(route('items.index'))->assertOk()->assertDontSee(route('catalog.setup'));
        }
        $this->assertSame(2, InventoryItem::where('sku', 'like', 'NEW%')->count());

        $this->actingAs($this->staff)->post(route('items.store'), $item('NOPE'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('catalog.setup'))->assertOk();
    }
}
