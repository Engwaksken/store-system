<?php

namespace App\Services;

use App\Models\CostCode;
use App\Models\Expense;
use App\Models\ExpenseClaim;
use App\Models\MaterialIssue;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\StockReturn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ACTUAL project cost = materials ISSUED from a store (valued at issue-time average cost)
 *                     − materials RETURNED to a store (credited at the same voucher cost)
 *                     + cash EXPENSES recorded against the project (incl. paid claims).
 *
 * COMMITTED (not yet cost) = open value of approved project POs (ordered − received) × PO price
 *                          + approved expense claims not yet paid.
 *
 * Store purchases (stock receipts) are deliberately excluded from actual cost: they are company stock,
 * not project cost, until issued. Counting both would double-count materials (MVP_SCOPE D9).
 */
class ProjectCostService
{
    public const WARN_PCT = 90;

    /** @return Collection<int, object> one row per project */
    public function summary(Collection $projects): Collection
    {
        $ids = $projects->pluck('id');
        $materials = MaterialIssue::whereIn('project_id', $ids)->groupBy('project_id')
            ->selectRaw('project_id, SUM(total_value) as v')->pluck('v', 'project_id');
        $returns = StockReturn::whereIn('project_id', $ids)->groupBy('project_id')
            ->selectRaw('project_id, SUM(total_value) as v')->pluck('v', 'project_id');
        $expenses = Expense::whereIn('project_id', $ids)->groupBy('project_id')
            ->selectRaw('project_id, SUM(amount) as v')->pluck('v', 'project_id');
        $poOpen = $this->openPoQuery()->whereIn('purchase_orders.project_id', $ids)->groupBy('purchase_orders.project_id')
            ->selectRaw('purchase_orders.project_id as k, '.$this->openValueSql().' as v')->pluck('v', 'k');
        $claims = ExpenseClaim::whereIn('project_id', $ids)->where('status', 'approved')->groupBy('project_id')
            ->selectRaw('project_id, SUM(amount) as v')->pluck('v', 'project_id');

        return $projects->map(function (Project $p) use ($materials, $returns, $expenses, $poOpen, $claims) {
            $returned = $this->money($returns[$p->id] ?? 0);
            $m = bcsub($this->money($materials[$p->id] ?? 0), $returned, 2);
            $e = $this->money($expenses[$p->id] ?? 0);
            $total = bcadd($m, $e, 2);
            $committed = bcadd($this->money($poOpen[$p->id] ?? 0), $this->money($claims[$p->id] ?? 0), 2);
            $budget = (string) $p->budget;

            return (object) [
                'project' => $p,
                'budget' => $budget,
                'materials' => $m,
                'returned' => $returned,
                'expenses' => $e,
                'total' => $total,
                'committed' => $committed,
                'exposure' => bcadd($total, $committed, 2),
                'remaining' => bcsub($budget, $total, 2),
                'uncommitted' => bcsub($budget, bcadd($total, $committed, 2), 2),
                'used_pct' => $this->pct($total, $budget),
                'exposure_pct' => $this->pct(bcadd($total, $committed, 2), $budget),
                'flag' => $this->flag(bcadd($total, $committed, 2), $budget),
            ];
        });
    }

    /**
     * Budget vs committed vs actual for one project, one row per cost code (+ an "Unallocated" row for cost
     * that carries no cost code: request lines without a BOQ line, expenses/POs without a code).
     *
     * @return Collection<int, object>
     */
    public function byCostCode(Project $project): Collection
    {
        $pid = $project->id;
        $budgets = $project->budgets()->pluck('amount', 'cost_code_id');

        $issued = DB::table('material_issue_lines as il')
            ->join('material_issues as i', 'i.id', '=', 'il.material_issue_id')
            ->join('material_request_lines as rl', 'rl.id', '=', 'il.material_request_line_id')
            ->leftJoin('boq_items as b', 'b.id', '=', 'rl.boq_item_id')
            ->where('i.project_id', $pid)->groupBy('b.cost_code_id')
            ->selectRaw('b.cost_code_id as k, SUM(il.line_value) as v')->pluck('v', 'k');
        $returned = DB::table('stock_return_lines as srl')
            ->join('stock_returns as sr', 'sr.id', '=', 'srl.stock_return_id')
            ->join('material_issue_lines as il', 'il.id', '=', 'srl.material_issue_line_id')
            ->join('material_request_lines as rl', 'rl.id', '=', 'il.material_request_line_id')
            ->leftJoin('boq_items as b', 'b.id', '=', 'rl.boq_item_id')
            ->where('sr.project_id', $pid)->groupBy('b.cost_code_id')
            ->selectRaw('b.cost_code_id as k, SUM(srl.line_value) as v')->pluck('v', 'k');
        $expenses = Expense::where('project_id', $pid)->groupBy('cost_code_id')->selectRaw('cost_code_id as k, SUM(amount) as v')->pluck('v', 'k');
        $poOpen = $this->openPoQuery()->where('purchase_orders.project_id', $pid)->groupBy('purchase_orders.cost_code_id')
            ->selectRaw('purchase_orders.cost_code_id as k, '.$this->openValueSql().' as v')->pluck('v', 'k');
        $claims = ExpenseClaim::where('project_id', $pid)->where('status', 'approved')->groupBy('cost_code_id')
            ->selectRaw('cost_code_id as k, SUM(amount) as v')->pluck('v', 'k');

        // pluck() turns a NULL key into "" — normalise so the unallocated bucket is keyed 0.
        $norm = fn (Collection $c) => $c->mapWithKeys(fn ($v, $k) => [(int) $k => $v]);
        [$issued, $returned, $expenses, $poOpen, $claims] = array_map($norm, [$issued, $returned, $expenses, $poOpen, $claims]);

        $codeIds = collect([$budgets, $issued, $returned, $expenses, $poOpen, $claims])->flatMap->keys()->unique()->reject(fn ($k) => $k === 0)->values();
        $codes = CostCode::whereIn('id', $codeIds)->orderBy('code')->get();
        $rows = $codes->map(fn ($c) => $this->row($c->label(), $c->id, $budgets[$c->id] ?? 0, $issued, $returned, $expenses, $poOpen, $claims));
        $unalloc = $this->row('Unallocated (no cost code)', 0, 0, $issued, $returned, $expenses, $poOpen, $claims);
        if (bccomp($unalloc->actual, '0', 2) !== 0 || bccomp($unalloc->committed, '0', 2) !== 0) {
            $rows->push($unalloc);
        }

        return $rows->values();
    }

    /** BOQ lines with materials consumed (issued − returned value) against each. */
    public function boqConsumption(Project $project): Collection
    {
        $issued = DB::table('material_issue_lines as il')
            ->join('material_request_lines as rl', 'rl.id', '=', 'il.material_request_line_id')
            ->whereNotNull('rl.boq_item_id')
            ->join('boq_items as b', 'b.id', '=', 'rl.boq_item_id')->where('b.project_id', $project->id)
            ->groupBy('rl.boq_item_id')->selectRaw('rl.boq_item_id as k, SUM(il.line_value) as v, SUM(il.qty) as q')->get()->keyBy('k');
        $returned = DB::table('stock_return_lines as srl')
            ->join('material_issue_lines as il', 'il.id', '=', 'srl.material_issue_line_id')
            ->join('material_request_lines as rl', 'rl.id', '=', 'il.material_request_line_id')
            ->whereNotNull('rl.boq_item_id')
            ->groupBy('rl.boq_item_id')->selectRaw('rl.boq_item_id as k, SUM(srl.line_value) as v, SUM(srl.qty) as q')->get()->keyBy('k');

        return $project->boqItems()->with('costCode', 'item.unit')->get()->map(function ($b) use ($issued, $returned) {
            $value = bcsub($this->money($issued[$b->id]->v ?? 0), $this->money($returned[$b->id]->v ?? 0), 2);
            $qty = bcsub(bcadd((string) ($issued[$b->id]->q ?? 0), '0', 3), bcadd((string) ($returned[$b->id]->q ?? 0), '0', 3), 3);

            return (object) ['boq' => $b, 'consumed_value' => $value, 'consumed_qty' => $qty, 'pct' => $this->pct($value, (string) $b->amount)];
        });
    }

    // ---------------------------------------------------------------- helpers

    private function row(string $label, int $id, $budget, ...$maps): object
    {
        [$issued, $returned, $expenses, $poOpen, $claims] = $maps;
        $materials = bcsub($this->money($issued[$id] ?? 0), $this->money($returned[$id] ?? 0), 2);
        $exp = $this->money($expenses[$id] ?? 0);
        $actual = bcadd($materials, $exp, 2);
        $committed = bcadd($this->money($poOpen[$id] ?? 0), $this->money($claims[$id] ?? 0), 2);
        $budget = $this->money($budget);
        $exposure = bcadd($actual, $committed, 2);

        return (object) [
            'label' => $label, 'cost_code_id' => $id ?: null, 'budget' => $budget, 'materials' => $materials, 'expenses' => $exp,
            'actual' => $actual, 'committed' => $committed, 'exposure' => $exposure, 'variance' => bcsub($budget, $exposure, 2),
            'pct' => $this->pct($exposure, $budget), 'flag' => $id ? $this->flag($exposure, $budget) : (bccomp($exposure, '0', 2) > 0 ? 'warn' : 'ok'),
        ];
    }

    private function openPoQuery()
    {
        return DB::table('purchase_order_lines')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->whereIn('purchase_orders.status', PurchaseOrder::RECEIVABLE)
            ->whereNotNull('purchase_orders.project_id');
    }

    private function openValueSql(): string
    {
        return 'SUM((purchase_order_lines.qty - purchase_order_lines.received_qty) * purchase_order_lines.unit_price)';
    }

    /** 'over' above 100% of budget, 'warn' from WARN_PCT, else 'ok'. A zero budget with any exposure is 'over'. */
    public function flag(string $exposure, string $budget): string
    {
        if (bccomp($budget, '0', 2) <= 0) {
            return bccomp($exposure, '0', 2) > 0 ? 'over' : 'ok';
        }
        if (bccomp($exposure, $budget, 2) > 0) {
            return 'over';
        }

        return bccomp(bcmul($exposure, '100', 2), bcmul($budget, (string) self::WARN_PCT, 2), 2) >= 0 ? 'warn' : 'ok';
    }

    private function pct(string $part, string $whole): ?float
    {
        return bccomp($whole, '0', 2) > 0 ? round((float) bcdiv(bcmul($part, '100', 4), $whole, 4), 1) : null;
    }

    private function money(mixed $v): string
    {
        return bcadd((string) ($v ?? 0), '0', 2);
    }
}
