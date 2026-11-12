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
use App\Services\StockService;
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
        $acc = $mk('Joan Accounts', 'accounts@demo.test', Role::Accountant);
        $s1 = $mk('Brian Site', 'staff1@demo.test', Role::Staff);
        $s2 = $mk('Esther Site', 'staff2@demo.test', Role::Staff);

        $p1 = Project::create(['code' => 'PRJ-001', 'name' => 'Kira Primary School Block', 'client' => 'Kira Municipal Council', 'location' => 'Kira', 'status' => 'active', 'budget' => 180000000, 'manager_id' => $pm1->id, 'start_date' => now()->subMonths(2), 'created_by' => $owner->id]);
        $p2 = Project::create(['code' => 'PRJ-002', 'name' => 'Ntinda Apartments Phase 1', 'client' => 'Private client', 'location' => 'Ntinda', 'status' => 'active', 'budget' => 420000000, 'manager_id' => $pm2->id, 'start_date' => now()->subMonth(), 'created_by' => $owner->id]);
        $p1->members()->sync([$s1->id]);
        $p2->members()->sync([$s2->id]);

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

        $this->command?->info('Demo data loaded. All demo accounts use password Demo12345: owner@, overall@, pm1@, pm2@, store@, accounts@, staff1@, staff2@demo.test');
    }
}
