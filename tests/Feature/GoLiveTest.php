<?php

namespace Tests\Feature;

use Database\Seeders\BaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 6: the full demo data set (every module exercised) reconciles, and tampering is caught. */
class GoLiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([BaseSeeder::class, DemoSeeder::class]);
    }

    public function test_demo_books_reconcile_and_audit_chain_is_intact(): void
    {
        $code = Artisan::call('cms:reconcile');
        $out = Artisan::output(); // output() drains the buffer, so read it once
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('All books reconcile', $out);
        $code = Artisan::call('cms:verify-audit');
        $this->assertSame(0, $code, Artisan::output());
    }

    public function test_reconcile_catches_a_balance_edited_outside_the_ledger(): void
    {
        $b = DB::table('inventory_balances')->where('on_hand', '>', 0)->first();
        DB::table('inventory_balances')->where('id', $b->id)->update(['on_hand' => $b->on_hand + 1]);
        $this->assertSame(1, Artisan::call('cms:reconcile'));
        $this->assertStringContainsString("store {$b->store_id} item {$b->item_id}: ledger", Artisan::output());
    }

    public function test_reconcile_catches_purchasing_and_payment_counters_out_of_step(): void
    {
        DB::table('purchase_order_lines')->where('received_qty', '>', 0)->limit(1)->update(['received_qty' => DB::raw('received_qty + 1')]);
        DB::table('purchase_invoices')->limit(1)->update(['amount_paid' => 1]);
        $this->assertSame(1, Artisan::call('cms:reconcile'));
        $out = Artisan::output();
        $this->assertStringContainsString('FAIL  PO received = GRNs', $out);
        $this->assertStringContainsString('FAIL  Invoice paid = payments', $out);
    }

    public function test_go_live_check_flags_demo_data_and_reports_reconciliation(): void
    {
        Artisan::call('cms:go-live-check');
        $out = Artisan::output();
        $this->assertMatchesRegularExpression('/(WARN|FAIL)\s*\|\s*No demo accounts/', $out);
        $this->assertMatchesRegularExpression('/PASS\s*\|\s*Books reconcile/', $out);
        $this->assertMatchesRegularExpression('/PASS\s*\|\s*Audit chain intact/', $out);
    }
}
