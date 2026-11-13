<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Models\MaterialIssueLine;
use App\Models\MaterialRequestLine;
use App\Models\Project;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\RequestWorkflow;
use App\Services\StockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * UAT evidence for "simultaneous issue of final units cannot overspend balance or approved allocation".
 *
 * Spawns N separate PHP processes that hit IssueService at the same instant against a real MySQL server.
 * DESTRUCTIVE to the configured database: refuses to run unless the DB name contains "test".
 *
 *   DB_DATABASE=cms_concurrency_test php artisan cms:concurrency-test --workers=8
 */
class ConcurrencyTest extends Command
{
    protected $signature = 'cms:concurrency-test {--workers=8}';

    protected $description = 'Race N parallel issue attempts against MySQL and verify no overspend (UAT evidence).';

    public function handle(): int
    {
        $db = DB::connection()->getDatabaseName();
        if (app()->isProduction() || DB::getDriverName() !== 'mysql' || ! str_contains($db, 'test')) {
            $this->error("Refusing: needs a non-production MySQL database whose name contains 'test' (current: ".DB::getDriverName()." / {$db}).");

            return self::FAILURE;
        }
        $n = max(2, (int) $this->option('workers'));
        Artisan::call('migrate:fresh', ['--force' => true]);

        $sk = User::create(['name' => 'Store', 'email' => 'sk@race.test', 'password' => 'x', 'role' => Role::Storekeeper]);
        $sk->stores()->attach(\App\Models\Store::DEFAULT_ID);
        $pm = User::create(['name' => 'PM', 'email' => 'pm@race.test', 'password' => 'x', 'role' => Role::ProjectManager]);
        $om = User::create(['name' => 'OM', 'email' => 'om@race.test', 'password' => 'x', 'role' => Role::OverallManager]);
        $st = User::create(['name' => 'Staff', 'email' => 'st@race.test', 'password' => 'x', 'role' => Role::Staff]);
        $project = Project::create(['code' => 'RACE', 'name' => 'Race', 'status' => 'active', 'budget' => 0, 'manager_id' => $pm->id]);
        $project->members()->attach($st->id);
        $cat = InventoryCategory::create(['name' => 'C']);
        $unit = InventoryUnit::create(['name' => 'Bag', 'abbreviation' => 'bag']);
        $a = InventoryItem::create(['sku' => 'A', 'name' => 'Scarce item', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'is_active' => true]);
        $b = InventoryItem::create(['sku' => 'B', 'name' => 'Plentiful item', 'category_id' => $cat->id, 'unit_id' => $unit->id, 'is_active' => true]);
        app(StockService::class)->receive($sk, ['type' => 'opening', 'received_on' => now()->toDateString()], [
            ['item_id' => $a->id, 'qty' => 5, 'unit_cost' => 1000], ['item_id' => $b->id, 'qty' => 1000, 'unit_cost' => 1000],
        ]);
        $wf = app(RequestWorkflow::class);
        $approve = function ($item, $qty) use ($wf, $st, $pm, $om, $project) {
            $r = $wf->createDraft($st, $project, 'race', null, [['item_id' => $item->id, 'requested_qty' => $qty]]);
            $wf->submit($st, $r);
            $wf->pmDecide($pm, $r, 'approved', null);
            $wf->overallDecide($om, $r, 'approved', null);

            return $r->lines()->first();
        };

        // Scenario 1: N different approved requests (5 each) race for the last 5 units in stock.
        $s1 = collect(range(1, $n))->map(fn () => $approve($a, 5));
        $ok1 = $this->race($s1->map(fn ($l) => [$sk->id, $l->material_request_id, $l->id, '5'])->all());

        // Scenario 2: N workers race to issue 4 each against ONE request approved for 10 (stock is plentiful).
        $line = $approve($b, 10);
        $ok2 = $this->race(array_fill(0, $n, [$sk->id, $line->material_request_id, $line->id, '4']));

        $onHandA = (string) DB::table('inventory_balances')->where('item_id', $a->id)->value('on_hand');
        $issuedB = (string) MaterialRequestLine::find($line->id)->issued_qty;
        $ledgerA = (string) StockMovement::where('item_id', $a->id)->sum('qty_delta');
        $issueLinesA = (string) MaterialIssueLine::where('item_id', $a->id)->sum('qty');

        $checks = [
            "S1: exactly one of {$n} competing issues succeeded" => $ok1 === 1,
            "S1: on hand for scarce item is 0 (got {$onHandA})" => bccomp($onHandA, '0', 3) === 0,
            "S1: ledger sum equals on hand ({$ledgerA})" => bccomp($ledgerA, $onHandA, 3) === 0,
            "S1: total issued equals opening stock 5 ({$issueLinesA})" => bccomp($issueLinesA, '5', 3) === 0,
            "S2: at most 2 x 4 issued against approval of 10 (successes: {$ok2}, issued {$issuedB})" => $ok2 === 2 && bccomp($issuedB, '8', 3) === 0,
            'No negative balances anywhere' => DB::table('inventory_balances')->where('on_hand', '<', 0)->doesntExist(),
        ];
        foreach ($checks as $label => $pass) {
            $this->line(($pass ? '<info>PASS</info> ' : '<error>FAIL</error> ').$label);
        }

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    /** Launch all workers, released at the same wall-clock instant; returns number of successful issues. */
    private function race(array $jobs): int
    {
        $startAt = microtime(true) + 1.5;
        $procs = [];
        foreach ($jobs as $i => [$userId, $reqId, $lineId, $qty]) {
            $cmd = [PHP_BINARY, base_path('artisan'), 'cms:concurrency-worker', $userId, $reqId, $lineId, $qty, (string) $startAt];
            $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], base_path());
        }
        $ok = 0;
        foreach ($procs as $i => $p) {
            $out = trim(stream_get_contents($pipes[$i][1]).stream_get_contents($pipes[$i][2]));
            proc_close($p);
            $this->line("  worker {$i}: {$out}");
            $ok += str_starts_with($out, 'OK') ? 1 : 0;
        }

        return $ok;
    }
}
