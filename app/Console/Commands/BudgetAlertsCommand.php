<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectCostService;
use App\Services\WorkflowNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Variance alerts. When a project (or one of its cost-code budgets) reaches the warn level (≥ 90 % of budget used or committed)
 * or goes over budget, tell the Owner/Admin, Overall Manager, Accountant and the project's PM — once per level.
 * If the figure falls back below the level, the alert is re-armed.
 */
class BudgetAlertsCommand extends Command
{
    protected $signature = 'cms:budget-alerts';

    protected $description = 'Notify management when projects or cost codes approach or exceed their budgets';

    public function handle(ProjectCostService $costs, WorkflowNotifier $n): int
    {
        $sent = 0;
        foreach (Project::whereIn('status', ['planned', 'active', 'on_hold'])->get() as $p) {
            $sum = $costs->summary(collect([$p]))->first();
            $checks = collect([(object) ['code' => 0, 'label' => 'the whole project', 'flag' => $sum->flag, 'exposure' => $sum->exposure, 'budget' => $sum->budget]])
                ->concat($costs->byCostCode($p)->map(fn ($r) => (object) ['code' => (int) $r->cost_code_id, 'label' => $r->label, 'flag' => $r->flag, 'exposure' => $r->exposure, 'budget' => $r->budget]))
                ->filter(fn ($c) => bccomp($c->budget, '0', 2) > 0); // nothing to compare against without a budget

            foreach ($checks as $c) {
                $levels = match ($c->flag) { 'over' => ['warn', 'over'], 'warn' => ['warn'], default => [] };
                // Re-arm levels that are no longer reached.
                DB::table('budget_alerts')->where('project_id', $p->id)->where('cost_code_id', $c->code)->whereNotIn('level', $levels ?: ['-'])->delete();
                $level = end($levels);
                if (! $level) {
                    continue;
                }
                $new = DB::table('budget_alerts')->insertOrIgnore(['project_id' => $p->id, 'cost_code_id' => $c->code, 'level' => $level, 'notified_at' => now()]);
                if ($new) {
                    $to = User::whereIn('role', [Role::Admin->value, Role::OverallManager->value, Role::Accountant->value])->orWhere('id', $p->manager_id)->get();
                    $what = $level === 'over' ? 'is OVER budget' : 'has reached '.ProjectCostService::WARN_PCT.'% of budget';
                    $n->send($to, "Budget alert: {$p->code} {$what}", "{$p->code} ({$c->label}): actual + committed ".ugx($c->exposure).' '.currency().' against a budget of '.ugx($c->budget).' '.currency().'.',
                        route('projects.controls', $p), 'Open project controls', $level === 'over' ? 'bad' : 'warn');
                    $sent++;
                }
            }
        }
        $this->info("Budget alerts raised: {$sent}.");

        return self::SUCCESS;
    }
}
