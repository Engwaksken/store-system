<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Database\Seeder;

/**
 * SAMPLE construction catalogue: 20 common items with an opening balance in the Main store.
 *
 *   php artisan db:seed --class=SampleCatalogSeeder --force
 *
 * Safe to run more than once: items are matched by SKU, and an opening balance is posted only for items that
 * have no stock history in the Main store yet. Stock goes through StockService (opening receipt + ledger),
 * so balances, average cost and reconciliation stay consistent. The receipt is posted by the first active
 * Owner/Admin and is marked "Sample opening stock" in its notes.
 */
class SampleCatalogSeeder extends Seeder
{
    /** [sku, name, category, unit abbreviation, minimum level, unit cost, opening qty] */
    public const ITEMS = [
        ['CEM-425', 'Cement 42.5R (50kg)', 'Cement & aggregates', 'bag', 50, 34000, 300],
        ['SND-BLD', 'Building sand (lorry)', 'Cement & aggregates', 'trip', 2, 300000, 5],
        ['AGG-20', 'Aggregate 20mm (ballast, lorry)', 'Cement & aggregates', 'trip', 2, 450000, 4],
        ['HCB-6', 'Hollow concrete block 6"', 'Blocks & bricks', 'pc', 500, 2800, 2500],
        ['BRK-CLY', 'Burnt clay brick (standard)', 'Blocks & bricks', 'pc', 1000, 350, 8000],
        ['Y10', 'Reinforcement bar Y10 (12m)', 'Steel & reinforcement', 'pc', 40, 30000, 120],
        ['Y20', 'Reinforcement bar Y20 (12m)', 'Steel & reinforcement', 'pc', 10, 115000, 30],
        ['BND-WIRE', 'Binding wire', 'Steel & reinforcement', 'kg', 25, 9000, 80],
        ['TIM-2X6', 'Timber 2x6 (treated, 4.2m)', 'Timber', 'pc', 30, 18000, 60],
        ['PLY-18', 'Plywood 18mm (8x4 ft)', 'Timber', 'sht', 10, 85000, 25],
        ['IRS-30', 'Iron sheet G30 (3m, pre-painted)', 'Roofing', 'sht', 50, 32000, 100],
        ['NAIL-RF', 'Roofing nails', 'Roofing', 'kg', 10, 12000, 30],
        ['PPR-25', 'PPR pipe 25mm (4m)', 'Plumbing', 'pc', 20, 9500, 60],
        ['PVC-110', 'PVC pipe 110mm (6m)', 'Plumbing', 'pc', 10, 45000, 20],
        ['CBL-25', 'Cable 2.5mm twin & earth (100m)', 'Electrical', 'roll', 3, 280000, 6],
        ['CBL-15', 'Cable 1.5mm twin & earth (100m)', 'Electrical', 'roll', 3, 190000, 5],
        ['PNT-EMU', 'Emulsion paint, white', 'Paint & finishes', 'L', 40, 12000, 200],
        ['SCR-50', 'Wood screws 50mm (box of 200)', 'Hardware & fixings', 'box', 5, 15000, 20],
        ['WHB-HD', 'Wheelbarrow, heavy duty', 'Tools & consumables', 'pc', 2, 185000, 6],
        ['GLV-WRK', 'Work gloves (pair)', 'Tools & consumables', 'pc', 20, 6000, 50],
    ];

    public function run(): void
    {
        $this->callOnce(BaseSeeder::class);   // categories and units (idempotent)

        $created = 0;
        $items = [];
        foreach (self::ITEMS as [$sku, $name, $cat, $unit, $min, $cost, $qty]) {
            $item = InventoryItem::firstOrCreate(['sku' => $sku], [
                'name' => $name,
                'min_level' => $min,
                'is_active' => true,
                'category_id' => InventoryCategory::firstOrCreate(['name' => $cat])->id,
                'unit_id' => InventoryUnit::where('abbreviation', $unit)->value('id'),
            ]);
            $created += $item->wasRecentlyCreated ? 1 : 0;
            $items[] = [$item, $qty, $cost];
        }

        $withHistory = StockMovement::where('store_id', Store::DEFAULT_ID)
            ->whereIn('item_id', collect($items)->map(fn ($i) => $i[0]->id))->pluck('item_id')->unique()->all();
        $lines = collect($items)
            ->reject(fn ($i) => $i[1] <= 0 || in_array($i[0]->id, $withHistory, true))
            ->map(fn ($i) => ['item_id' => $i[0]->id, 'qty' => $i[1], 'unit_cost' => $i[2]])
            ->values()->all();

        $admin = User::where('role', Role::Admin)->where('is_active', true)->orderBy('id')->first();
        if ($lines && ! $admin) {
            $this->command?->error('No active Owner/Admin to post the opening stock. Run BaseSeeder first.');

            return;
        }
        if ($lines) {
            $receipt = app(StockService::class)->receive($admin, [
                'type' => 'opening',
                'store_id' => Store::DEFAULT_ID,
                'received_on' => now()->toDateString(),
                'notes' => 'Sample opening stock (SampleCatalogSeeder)',
            ], $lines);
            $this->command?->info("Opening stock posted to the Main store: {$receipt->receipt_no} (".count($lines).' items).');
        }
        $this->command?->info("Sample catalogue: {$created} new item(s), ".(count(self::ITEMS) - $created).' already existed.');
    }
}
