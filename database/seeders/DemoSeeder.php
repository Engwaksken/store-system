<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\Project;
use App\Models\User;
use App\Services\IssueService;
use App\Services\RequestWorkflow;
use App\Services\StockCountService;
use App\Services\StockReturnService;
use App\Services\StockService;
use App\Services\StockTransferService;
use App\Models\Store;
use App\Models\Supplier;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseInvoiceService;
use App\Services\PurchasingService;
use Illuminate\Database\Seeder;

/**
 * SAMPLE / DEMONSTRATION data for UAT and training. Every demo account uses password "Demo12345".
 * Never runs in production (DatabaseSeeder skips it).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('email', 'owner@demo.test')->exists()) {
            return;
        }
        $mk = fn (string $name, string $email, Role $role) => User::create(['name' => $name, 'email' => $email, 'password' => 'Demo12345', 'role' => $role]);
        $owner = $mk('Grace Owner', 'owner@demo.test', Role::Admin);
        $om = $mk('Samuel Overall', 'overall@demo.test', Role::OverallManager);
        $pm1 = $mk('Peter Kato (PM)', 'pm1@demo.test', Role::ProjectManager);
        $pm2 = $mk('Ruth Nakato (PM)', 'pm2@demo.test', Role::ProjectManager);
        $sk = $mk('Moses Store', 'store@demo.test', Role::Storekeeper);
        $sk2 = $mk('Agnes Site Store', 'sitestore@demo.test', Role::Storekeeper);
        $acc = $mk('Joan Accounts', 'accounts@demo.test', Role::Accountant);
        $buyer = $mk('Daniel Procurement', 'buyer@demo.test', Role::Procurement);
        $s1 = $mk('Brian Site', 'staff1@demo.test', Role::Staff);
        $s2 = $mk('Esther Site', 'staff2@demo.test', Role::Staff);

        $p1 = Project::create(['code' => 'PRJ-001', 'name' => 'Kira Primary School Block', 'client' => 'Kira Municipal Council', 'location' => 'Kira', 'status' => 'active', 'budget' => 180000000, 'manager_id' => $pm1->id, 'start_date' => now()->subMonths(2), 'created_by' => $owner->id]);
        $p2 = Project::create(['code' => 'PRJ-002', 'name' => 'Ntinda Apartments Phase 1', 'client' => 'Private client', 'location' => 'Ntinda', 'status' => 'active', 'budget' => 420000000, 'manager_id' => $pm2->id, 'start_date' => now()->subMonth(), 'created_by' => $owner->id]);
        $p1->members()->sync([$s1->id]);
        $p2->members()->sync([$s2->id]);

        $sk->stores()->attach(Store::DEFAULT_ID);
        $site = Store::create(['code' => 'KIRA-SITE', 'name' => 'Kira Site Store', 'type' => 'site', 'project_id' => $p1->id, 'location' => 'Kira']);
        $site->users()->attach([$sk2->id, $sk->id]);

        // [sku, name, category, unit, min level, unit cost, opening qty]
        $spec = [
            ['CEM-325', 'Cement 32.5N (50kg)', 'Cement & aggregates', 'bag', 50, 30000, 400],
            ['SND-PLS', 'Plaster sand', 'Cement & aggregates', 'trip', 2, 350000, 6],
            ['Y12', 'Reinforcement bar Y12 (12m)', 'Steel & reinforcement', 'pc', 40, 42000, 150],
            ['Y16', 'Reinforcement bar Y16 (12m)', 'Steel & reinforcement', 'pc', 20, 74000, 60],
            ['BRC-65', 'BRC mesh A65', 'Steel & reinforcement', 'roll', 2, 380000, 4],
            ['NAIL-4', 'Wire nails 4"', 'Hardware & fixings', 'kg', 20, 7500, 50],
            ['TIM-2X4', 'Timber 2x4 (treated)', 'Timber', 'pc', 50, 12000, 120],
            ['IRS-28', 'Iron sheets G28 (3m)', 'Roofing', 'sht', 30, 38000, 0],
        ];
        $items = [];
        $opening = [];
        foreach ($spec as [$sku, $name, $cat, $unit, $min, $cost, $openQty]) {
            $item = InventoryItem::create([
                'sku' => $sku, 'name' => $name, 'min_level' => $min, 'is_active' => true,
                'category_id' => InventoryCategory::where('name', $cat)->value('id'),
                'unit_id' => InventoryUnit::where('abbreviation', $unit)->value('id'),
            ]);
            $items[] = $item;
            if ($openQty > 0) {
                $opening[] = ['item_id' => $item->id, 'qty' => $openQty, 'unit_cost' => $cost];
            }
        }

        $stock = app(StockService::class);
        $stock->receive($sk, ['type' => 'opening', 'received_on' => now()->subMonths(2)->toDateString(), 'notes' => 'Physical count at go-live'], $opening);
        $stock->receive($sk, ['type' => 'purchase', 'received_on' => now()->subWeek()->toDateString(), 'supplier_name' => 'Kampala Hardware Ltd', 'reference' => 'INV-88412'],
            [['item_id' => $items[0]->id, 'qty' => 200, 'unit_cost' => 31000], ['item_id' => $items[7]->id, 'qty' => 80, 'unit_cost' => 38500]]);

        $wf = app(RequestWorkflow::class);
        $issues = app(IssueService::class);

        // 1) Fully approved (overall reduced Y12 to 50) and partially issued.
        $r1 = $wf->createDraft($s1, $p1, 'Ground floor slab casting', now()->addDays(2)->toDateString(), [
            ['item_id' => $items[0]->id, 'requested_qty' => 120], ['item_id' => $items[2]->id, 'requested_qty' => 60],
        ]);
        $wf->submit($s1, $r1);
        $wf->pmDecide($pm1, $r1, 'approved', 'OK for slab');
        $lines = $r1->lines()->orderBy('id')->get();
        $wf->overallDecide($om, $r1, 'approved', null, [$lines[1]->id => 50]);
        $issue = $issues->issue($sk, $r1, [$lines[0]->id => 80, $lines[1]->id => 50], 'Brian Site');
        $issues->acknowledge($s1, $issue, 'Brian Site', 'Received in good condition');

        // 2) Awaiting Overall Manager.
        $r2 = $wf->createDraft($s2, $p2, 'Columns level 1', now()->addDays(5)->toDateString(), [['item_id' => $items[3]->id, 'requested_qty' => 30]]);
        $wf->submit($s2, $r2);
        $wf->pmDecide($pm2, $r2, 'approved', null);

        // 3) Awaiting PM.
        $r3 = $wf->createDraft($s1, $p1, 'Roofing - classroom 1', null, [['item_id' => $items[7]->id, 'requested_qty' => 40], ['item_id' => $items[5]->id, 'requested_qty' => 10]]);
        $wf->submit($s1, $r3);

        // 4) Rejected by PM (requester can revise).
        $r4 = $wf->createDraft($s2, $p2, 'Formwork', null, [['item_id' => $items[6]->id, 'requested_qty' => 200]]);
        $wf->submit($s2, $r4);
        $wf->pmDecide($pm2, $r4, 'rejected', 'Quantity too high for this stage; resubmit for level 1 only.');

        // Stores: one received transfer to the Kira site store, one still in transit, a return and a count awaiting approval.
        $transfers = app(StockTransferService::class);
        $t1 = $transfers->dispatch($sk, Store::DEFAULT_ID, $site->id, [['item_id' => $items[0]->id, 'qty' => 40], ['item_id' => $items[6]->id, 'qty' => 30]], 'Lorry UAX 123B');
        $t1Lines = $t1->lines()->orderBy('item_id')->get();
        $transfers->receive($sk2, $t1, [$t1Lines[0]->id => 40, $t1Lines[1]->id => 28], [$t1Lines[1]->id => 'Two pieces split in transit'], 'Offloaded at site');
        $transfers->dispatch($sk, Store::DEFAULT_ID, $site->id, [['item_id' => $items[5]->id, 'qty' => 10]], 'Pick-up');
        app(StockReturnService::class)->record($sk, $issue, [$issue->lines()->orderBy('id')->first()->id => 5], 'Brian Site', 'Excess after slab casting, bags intact');
        $counts = app(StockCountService::class);
        $count = $counts->create($sk2, $site->id, null, 'Weekly site count');
        $counts->record($sk2, $count, $count->lines()->get()->mapWithKeys(fn ($l) => [$l->id => [
            'counted_qty' => (float) $l->system_qty > 0 && $l->item_id === $items[0]->id ? (float) $l->system_qty - 1 : (float) $l->system_qty,
            'reason' => $l->item_id === $items[0]->id ? 'One bag hardened (water damage)' : null,
        ]])->all());
        $counts->submit($sk2, $count);

        // Procurement: suppliers, an approved PR with two quotes, a PO partly delivered and invoiced, and a PO awaiting approval.
        $sup1 = Supplier::create(['code' => 'KHL', 'name' => 'Kampala Hardware Ltd', 'contact_person' => 'Sarah', 'phone' => '0772 000111', 'payment_terms' => '30 days']);
        $sup2 = Supplier::create(['code' => 'RSM', 'name' => 'Roofing & Steel Mart', 'contact_person' => 'Ivan', 'phone' => '0701 222333', 'payment_terms' => 'Cash on delivery']);
        $buy = app(PurchasingService::class);
        $pr = $buy->createRequisition($sk, ['store_id' => Store::DEFAULT_ID, 'purpose' => 'Iron sheets for Kira roofing', 'project_id' => null],
            [['item_id' => $items[7]->id, 'qty' => 120, 'est_unit_cost' => 38000]]);
        $buy->submitRequisition($sk, $pr);
        $pr = $buy->decideRequisition($om, $pr, 'approved', null);
        $prLine = $pr->lines()->first();
        $buy->addQuote($buyer, $pr, $sup1->id, [$prLine->id => 39000], ['quote_ref' => 'Q-551']);
        $q2 = $buy->addQuote($buyer, $pr, $sup2->id, [$prLine->id => 37500], ['quote_ref' => 'RSM/88']);
        $buy->selectQuote($buyer, $q2, null);
        $po = $buy->createOrder($buyer, ['purchase_requisition_id' => $pr->id, 'supplier_id' => $sup2->id, 'store_id' => Store::DEFAULT_ID,
            'expected_on' => now()->addDays(3)->toDateString(), 'terms' => 'Delivery to main store'], [['item_id' => $items[7]->id, 'qty' => 120, 'unit_price' => 37500]]);
        $po = $buy->decideOrder($om, $po, 'approved', null);
        $poLine = $po->lines()->first();
        app(GoodsReceiptService::class)->receive($sk, $po, [$poLine->id => 80], ['reference' => 'DN-3021']);
        app(PurchaseInvoiceService::class)->record($acc, $po->fresh(), ['supplier_invoice_no' => 'RSM-INV-771', 'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString()], [$poLine->id => ['qty' => 80, 'unit_price' => 37500]]);
        $buy->createOrder($buyer, ['supplier_id' => $sup1->id, 'store_id' => $site->id, 'project_id' => $p1->id],
            [['item_id' => $items[2]->id, 'qty' => 60, 'unit_price' => 42000], ['item_id' => $items[1]->id, 'qty' => 2, 'unit_price' => 350000]]);

        $ec = fn (string $n) => ExpenseCategory::where('name', $n)->value('id');
        $rows = [
            [$p1, 'Casual labour', 1850000, 'cash', 'Weekly casuals week 1', 12],
            [$p1, 'Transport & fuel', 420000, 'mobile_money', 'Lorry hire for sand', 9],
            [$p2, 'Equipment hire', 2600000, 'bank_transfer', 'Concrete mixer, 2 weeks', 6],
            [$p2, 'Permits & fees', 900000, 'bank_transfer', 'Inspection fees', 3],
        ];
        foreach ($rows as [$p, $c, $amt, $mode, $desc, $daysAgo]) {
            Expense::create(['project_id' => $p->id, 'category_id' => $ec($c), 'incurred_on' => now()->subDays($daysAgo), 'amount' => $amt, 'payment_mode' => $mode, 'description' => $desc, 'recorded_by' => $acc->id]);
        }

        $this->command?->info('Demo data loaded. All demo accounts use password Demo12345: owner@, overall@, pm1@, pm2@, store@, sitestore@, accounts@, buyer@, staff1@, staff2@demo.test');
    }
}
