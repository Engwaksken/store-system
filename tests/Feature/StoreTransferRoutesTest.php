<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** HTTP-level checks for store master data and the dispatch → receive transfer flow. */
class StoreTransferRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function item(): InventoryItem
    {
        $category = InventoryCategory::create(['name' => 'Category '.uniqid()]);
        $unit = InventoryUnit::create(['name' => 'Unit '.uniqid(), 'abbreviation' => 'u'.uniqid()]);

        return InventoryItem::create(['sku' => 'SKU-'.uniqid(), 'name' => 'Item '.uniqid(), 'category_id' => $category->id, 'unit_id' => $unit->id, 'min_level' => 0, 'is_active' => true]);
    }

    private function store(array $attributes = []): Store
    {
        $code = 'ST-'.uniqid();

        return Store::create(array_merge(['code' => $code, 'name' => 'Store '.$code, 'is_active' => true], $attributes));
    }

    private function keeper(Store ...$stores): User
    {
        $u = User::factory()->role(Role::Storekeeper)->create();
        $u->stores()->attach(collect($stores)->pluck('id'));

        return $u;
    }

    private function stocked(User $actor, Store $store, InventoryItem $item, $qty): void
    {
        app(StockService::class)->receive($actor, ['type' => 'purchase', 'store_id' => $store->id, 'received_on' => now()->toDateString(), 'supplier_name' => 'S'],
            [['item_id' => $item->id, 'qty' => $qty, 'unit_cost' => 100]]);
    }

    public function test_admins_and_assigned_storekeepers_can_open_store_and_transfer_pages(): void
    {
        $store = $this->store();
        $main = Store::find(Store::DEFAULT_ID);
        foreach ([User::factory()->role(Role::Admin)->create(), $this->keeper($main, $store)] as $user) {
            $this->actingAs($user);
            $this->get(route('stores.index'))->assertOk();
            $this->get(route('stores.show', $store))->assertOk();
            $this->get(route('stores.create'))->assertOk();
            $this->get(route('stores.edit', $store))->assertOk();
            $this->get(route('transfers.index'))->assertOk();
            $this->get(route('transfers.create'))->assertOk();
        }
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get(route('stores.index'))->assertRedirect(route('login'));
        $this->get(route('transfers.index'))->assertRedirect(route('login'));
        $this->post(route('stores.store'), ['code' => 'G', 'name' => 'G'])->assertRedirect(route('login'));
        $this->post(route('transfers.store'), [])->assertRedirect(route('login'));
    }

    public function test_admin_creates_store_and_assigns_storekeepers(): void
    {
        $keeper = User::factory()->role(Role::Storekeeper)->create();
        $this->actingAs(User::factory()->role(Role::Admin)->create());
        $this->post(route('stores.store'), ['code' => 'NEW', 'name' => 'New store', 'type' => 'central', 'is_active' => 1, 'user_ids' => [$keeper->id]])
            ->assertRedirect()->assertSessionHas('status');
        $store = Store::where('code', 'NEW')->sole();
        $this->assertTrue($store->isOperableBy($keeper));

        $this->put(route('stores.update', $store), ['code' => 'NEW', 'name' => 'Updated', 'type' => 'central', 'is_active' => 1, 'user_ids' => ''])
            ->assertRedirect()->assertSessionHas('status');
        $this->assertSame('Updated', $store->fresh()->name);
        $this->assertFalse($store->fresh()->isOperableBy($keeper), 'Admin can clear assignments');
    }

    public function test_storekeeper_who_creates_a_store_operates_it_but_cannot_assign_others(): void
    {
        $other = User::factory()->role(Role::Storekeeper)->create();
        $keeper = $this->keeper();
        $this->actingAs($keeper)->post(route('stores.store'), ['code' => 'SK', 'name' => 'SK store', 'user_ids' => [$other->id]])->assertRedirect();
        $store = Store::where('code', 'SK')->sole();
        $this->assertTrue($store->isOperableBy($keeper));
        $this->assertFalse($store->isOperableBy($other));
    }

    public function test_site_store_requires_a_project(): void
    {
        $this->actingAs(User::factory()->role(Role::Admin)->create())
            ->post(route('stores.store'), ['code' => 'S1', 'name' => 'Site', 'type' => 'site'])->assertSessionHasErrors('project_id');
    }

    public function test_staff_cannot_manage_stores_or_transfers(): void
    {
        $this->actingAs(User::factory()->role(Role::Staff)->create());
        $this->post(route('stores.store'), ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->post(route('transfers.store'), [])->assertForbidden();
        $this->get(route('transfers.index'))->assertForbidden();
    }

    public function test_inactive_users_are_logged_out_before_any_action(): void
    {
        $this->actingAs(User::factory()->role(Role::Admin)->state(['is_active' => false])->create())
            ->post(route('stores.store'), ['code' => 'X', 'name' => 'X'])->assertRedirect(route('login'));
        $this->actingAs($this->keeper(Store::find(1))->forceFill(['is_active' => false]))
            ->post(route('transfers.store'), [])->assertRedirect(route('login'));
    }

    public function test_dispatch_then_receive_by_a_different_storekeeper(): void
    {
        $from = Store::find(Store::DEFAULT_ID);
        $to = $this->store(['code' => 'SITE']);
        $sender = $this->keeper($from);
        $receiver = $this->keeper($to);
        $item = $this->item();
        $this->stocked($sender, $from, $item, 10);

        $this->actingAs($sender)->post(route('transfers.store'), ['from_store_id' => $from->id, 'to_store_id' => $to->id, 'lines' => [['item_id' => $item->id, 'qty' => 4]]])
            ->assertRedirect()->assertSessionHas('status');
        $t = StockTransfer::sole();
        $this->assertSame('in_transit', $t->status);
        $this->assertSame('6.000', $item->onHand($from->id));
        $this->assertSame('0.000', $item->fresh()->onHand($to->id), 'Nothing arrives until receipt is confirmed');

        $line = $t->lines()->sole();
        $this->actingAs($sender)->post(route('transfers.receive', $t), ['received' => [$line->id => 4]])->assertForbidden();

        $this->actingAs($receiver)->post(route('transfers.receive', $t), ['received' => [$line->id => 4]])->assertRedirect()->assertSessionHas('status');
        $this->assertSame('received', $t->fresh()->status);
        $this->assertSame('4.000', $item->fresh()->onHand($to->id));
        $this->assertSame(['transfer_out', 'transfer_in'], StockMovement::where('source_type', 'stock_transfer')->orderBy('id')->pluck('movement_type')->all());
    }

    public function test_insufficient_stock_on_a_later_line_rolls_back_the_whole_dispatch(): void
    {
        $from = Store::find(Store::DEFAULT_ID);
        $to = $this->store();
        $actor = $this->keeper($from);
        [$item1, $item2] = [$this->item(), $this->item()];
        $this->stocked($actor, $from, $item1, 5);
        $this->stocked($actor, $from, $item2, 1);

        $this->actingAs($actor)->from(route('transfers.create'))->post(route('transfers.store'), [
            'from_store_id' => $from->id, 'to_store_id' => $to->id, 'lines' => [['item_id' => $item1->id, 'qty' => 2], ['item_id' => $item2->id, 'qty' => 2]],
        ])->assertRedirect(route('transfers.create'))->assertSessionHasErrors();
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertDatabaseCount('stock_transfer_lines', 0);
        $this->assertSame('5.000', $item1->onHand($from->id));
        $this->assertSame(2, StockMovement::count());
    }

    public function test_invalid_dispatches_write_nothing(): void
    {
        $from = Store::find(Store::DEFAULT_ID);
        $actor = $this->keeper($from);
        $item = $this->item();
        $this->stocked($actor, $from, $item, 5);
        $other = $this->store();
        $base = ['from_store_id' => $from->id, 'to_store_id' => $other->id, 'lines' => [['item_id' => $item->id, 'qty' => 1]]];
        foreach ([
            ['lines' => [['item_id' => $item->id, 'qty' => 1], ['item_id' => $item->id, 'qty' => 2]]], // duplicate lines
            ['to_store_id' => $from->id],                                                               // same store
            ['lines' => [['item_id' => $item->id, 'qty' => 0]]],                                        // non-positive
        ] as $override) {
            $this->actingAs($actor)->post(route('transfers.store'), array_merge($base, $override))->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_storekeeper_cannot_dispatch_from_a_store_they_are_not_assigned_to(): void
    {
        $from = Store::find(Store::DEFAULT_ID);
        $mine = $this->store();
        $owner = $this->keeper($from);
        $item = $this->item();
        $this->stocked($owner, $from, $item, 5);

        $this->actingAs($this->keeper($mine))->post(route('transfers.store'), [
            'from_store_id' => $from->id, 'to_store_id' => $mine->id, 'lines' => [['item_id' => $item->id, 'qty' => 1]],
        ])->assertForbidden();
        $this->assertSame('5.000', $item->onHand($from->id));
    }
}
