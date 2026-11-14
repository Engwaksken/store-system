<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\StockReceipt;
use App\Models\Store;
use Database\Seeders\BaseSeeder;
use Database\Seeders\SampleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SampleCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_twenty_items_with_opening_stock_once_and_books_reconcile(): void
    {
        $this->seed(BaseSeeder::class);
        $this->seed(SampleCatalogSeeder::class);
        $this->seed(SampleCatalogSeeder::class); // second run must not duplicate anything

        $this->assertSame(20, InventoryItem::count());
        $this->assertSame(1, StockReceipt::where('type', 'opening')->count());

        $cement = InventoryItem::where('sku', 'CEM-425')->firstOrFail();
        $this->assertSame('300', qty($cement->onHand(Store::DEFAULT_ID)));
        $this->assertEquals(34000, (float) $cement->avg_cost);
        $this->assertSame('8,000', qty(InventoryItem::where('sku', 'BRK-CLY')->first()->onHand()));

        $this->artisan('cms:reconcile')->assertSuccessful();
    }
}
