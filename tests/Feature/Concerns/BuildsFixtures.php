<?php

namespace Tests\Feature\Concerns;

use App\Enums\Role;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\RequestWorkflow;
use App\Services\StockService;

trait BuildsFixtures
{
    protected User $admin, $overall, $pm, $otherPm, $store, $accountant, $staff, $outsider;
    protected Project $project;
    protected InventoryItem $cement, $steel;

    protected function buildFixtures(): void
    {
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->overall = User::factory()->role(Role::OverallManager)->create();
        $this->pm = User::factory()->role(Role::ProjectManager)->create();
        $this->otherPm = User::factory()->role(Role::ProjectManager)->create();
        $this->store = User::factory()->role(Role::Storekeeper)->create();
        $this->accountant = User::factory()->role(Role::Accountant)->create();
        $this->staff = User::factory()->role(Role::Staff)->create();
        $this->outsider = User::factory()->role(Role::Staff)->create();

        $this->project = Project::create(['code' => 'P-1', 'name' => 'Test project', 'status' => 'active', 'budget' => 1000000, 'manager_id' => $this->pm->id]);
        $this->project->members()->attach($this->staff->id);
        Project::create(['code' => 'P-2', 'name' => 'Other project', 'status' => 'active', 'budget' => 500000, 'manager_id' => $this->otherPm->id]);

        $cat = InventoryCategory::create(['name' => 'Cement']);
        $unit = InventoryUnit::create(['name' => 'Bag', 'abbreviation' => 'bag']);
        $this->cement = InventoryItem::create(['sku' => 'CEM', 'name' => 'Cement', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'min_level' => 5, 'is_active' => true]);
        $this->steel = InventoryItem::create(['sku' => 'Y12', 'name' => 'Steel Y12', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'min_level' => 0, 'is_active' => true]);
    }

    protected function receive(InventoryItem $item, $qty, $cost = 1000, string $type = 'purchase'): void
    {
        app(StockService::class)->receive($this->store, ['type' => $type, 'received_on' => now()->toDateString(), 'supplier_name' => 'S'],
            [['item_id' => $item->id, 'qty' => $qty, 'unit_cost' => $cost]]);
    }

    protected function wf(): RequestWorkflow
    {
        return app(RequestWorkflow::class);
    }

    /** Draft → submitted request for $qty of cement by the assigned staff member. */
    protected function submittedRequest($qty = 10, ?InventoryItem $item = null, ?User $requester = null): MaterialRequest
    {
        $requester ??= $this->staff;
        $req = $this->wf()->createDraft($requester, $this->project, 'Slab', null, [['item_id' => ($item ?? $this->cement)->id, 'requested_qty' => $qty]]);

        return $this->wf()->submit($requester, $req);
    }

    protected function approvedRequest($qty = 10, ?InventoryItem $item = null): MaterialRequest
    {
        $req = $this->submittedRequest($qty, $item);
        $this->wf()->pmDecide($this->pm, $req, 'approved', null);
        $this->wf()->overallDecide($this->overall, $req, 'approved', null);

        return $req->fresh();
    }

    protected function onHand(InventoryItem $item): string
    {
        return (string) $item->balance()->first()->on_hand;
    }
}
