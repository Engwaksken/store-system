<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\MaterialIssue;
use App\Models\Project;
use App\Models\StockReturn;
use Illuminate\Support\Collection;

/**
 * Project cost = materials ISSUED from a store (valued at issue-time average cost)
 *              − materials RETURNED to a store (credited at the same voucher cost)
 *              + cash EXPENSES recorded against the project.
 *
 * Store purchases (stock receipts) are deliberately excluded: they are company stock, not project
 * cost, until issued. Counting both would double-count materials (MVP_SCOPE D9).
 */
class ProjectCostService
{
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

        return $projects->map(function (Project $p) use ($materials, $returns, $expenses) {
            $returned = bcadd((string) ($returns[$p->id] ?? 0), '0', 2);
            $m = bcsub(bcadd((string) ($materials[$p->id] ?? 0), '0', 2), $returned, 2);
            $e = bcadd((string) ($expenses[$p->id] ?? 0), '0', 2);
            $total = bcadd($m, $e, 2);

            return (object) [
                'project' => $p,
                'budget' => (string) $p->budget,
                'materials' => $m,
                'returned' => $returned,
                'expenses' => $e,
                'total' => $total,
                'remaining' => bcsub((string) $p->budget, $total, 2),
                'used_pct' => bccomp((string) $p->budget, '0', 2) > 0 ? round((float) $total / (float) $p->budget * 100, 1) : null,
            ];
        });
    }
}
