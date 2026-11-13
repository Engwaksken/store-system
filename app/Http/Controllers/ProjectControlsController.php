<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\BoqItem;
use App\Models\CostCode;
use App\Models\InventoryItem;
use App\Models\Project;
use App\Models\ProjectBudget;
use App\Models\ProjectMilestone;
use App\Services\ProjectCostService;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Budget by cost code, BOQ and milestones for one project. Editable by Admin, Overall Manager and the project's PM. */
class ProjectControlsController extends Controller
{
    public function show(Project $project, ProjectCostService $costs)
    {
        Gate::authorize('view-project-finance', $project);

        return view('projects.controls', [
            'project' => $project->load('milestones'),
            'summary' => $costs->summary(collect([$project]))->first(),
            'byCode' => $costs->byCostCode($project),
            'boq' => $costs->boqConsumption($project),
            'costCodes' => CostCode::where('is_active', true)->orderBy('code')->get(),
            'budgets' => $project->budgets()->pluck('amount', 'cost_code_id'),
            'items' => InventoryItem::where('is_active', true)->orderBy('name')->get(),
            'canEdit' => Gate::allows('manage-project-controls', $project),
        ]);
    }

    public function saveBudgets(Request $request, Project $project)
    {
        Gate::authorize('manage-project-controls', $project);
        $data = $request->validate(['budget' => 'required|array', 'budget.*' => 'nullable|numeric|min:0']);
        $before = $project->budgets()->pluck('amount', 'cost_code_id')->all();
        DB::transaction(function () use ($project, $data) {
            foreach ($data['budget'] as $codeId => $amount) {
                abort_unless(CostCode::whereKey($codeId)->exists(), 422);
                if ($amount === null || $amount === '' || (float) $amount == 0.0) {
                    ProjectBudget::where('project_id', $project->id)->where('cost_code_id', $codeId)->delete();
                } else {
                    ProjectBudget::updateOrCreate(['project_id' => $project->id, 'cost_code_id' => $codeId], ['amount' => Decimal::of($amount, 2)]);
                }
            }
        });
        $total = $project->budgets()->sum('amount');
        ActivityLog::record('project.budgets', "Cost-code budgets updated for {$project->code}", $project, ['before' => $before, 'after' => $project->budgets()->pluck('amount', 'cost_code_id')->all()]);
        $msg = 'Budgets saved.';
        if (bccomp(bcadd((string) $total, '0', 2), (string) $project->budget, 2) !== 0) {
            $msg .= ' Note: cost-code budgets total '.ugx($total).' UGX but the project budget is '.ugx($project->budget).' UGX.';
        }

        return redirect()->route('projects.controls', $project)->with('status', $msg);
    }

    public function storeBoq(Request $request, Project $project)
    {
        Gate::authorize('manage-project-controls', $project);
        $data = $this->boqData($request, $project);
        $boq = BoqItem::create($data + ['project_id' => $project->id]);
        ActivityLog::record('boq.created', "BOQ {$boq->ref} added to {$project->code}", $boq);

        return redirect()->route('projects.controls', $project)->with('status', "BOQ line {$boq->ref} added.");
    }

    public function updateBoq(Request $request, Project $project, BoqItem $boq)
    {
        Gate::authorize('manage-project-controls', $project);
        abort_unless($boq->project_id === $project->id, 404);
        $before = $boq->only('qty', 'rate', 'amount', 'cost_code_id');
        $boq->update($this->boqData($request, $project, $boq));
        ActivityLog::record('boq.updated', "BOQ {$boq->ref} on {$project->code} updated", $boq, ['before' => $before, 'after' => $boq->only('qty', 'rate', 'amount', 'cost_code_id')]);

        return redirect()->route('projects.controls', $project)->with('status', "BOQ line {$boq->ref} updated.");
    }

    public function destroyBoq(Project $project, BoqItem $boq)
    {
        Gate::authorize('manage-project-controls', $project);
        abort_unless($boq->project_id === $project->id, 404);
        if ($boq->requestLines()->exists()) {
            return back()->withErrors(['boq' => "BOQ line {$boq->ref} is used by material requests and cannot be removed."]);
        }
        $boq->delete();
        ActivityLog::record('boq.deleted', "BOQ {$boq->ref} removed from {$project->code}", $project);

        return back()->with('status', 'BOQ line removed.');
    }

    public function storeMilestone(Request $request, Project $project)
    {
        Gate::authorize('manage-project-controls', $project);
        $data = $request->validate(['name' => 'required|string|max:200', 'planned_on' => 'nullable|date', 'notes' => 'nullable|string|max:500']);
        $m = $project->milestones()->create($data + ['sort' => (int) $project->milestones()->max('sort') + 1]);
        ActivityLog::record('milestone.created', "Milestone '{$m->name}' added to {$project->code}", $m);

        return back()->with('status', 'Milestone added.');
    }

    public function completeMilestone(Request $request, Project $project, ProjectMilestone $milestone)
    {
        Gate::authorize('manage-project-controls', $project);
        abort_unless($milestone->project_id === $project->id, 404);
        $data = $request->validate(['completed_on' => 'nullable|date|before_or_equal:today']);
        $milestone->update(['completed_on' => $data['completed_on'] ?? null]);
        ActivityLog::record('milestone.updated', "Milestone '{$milestone->name}' on {$project->code} ".($milestone->completed_on ? 'completed' : 're-opened'), $milestone);

        return back()->with('status', 'Milestone updated.');
    }

    private function boqData(Request $request, Project $project, ?BoqItem $boq = null): array
    {
        $data = $request->validate([
            'ref' => ['required', 'string', 'max:30', Rule::unique('boq_items')->where('project_id', $project->id)->ignore($boq?->id)],
            'description' => 'required|string|max:500',
            'cost_code_id' => ['required', Rule::exists('cost_codes', 'id')->where('is_active', true)],
            'unit' => 'required|string|max:20',
            'qty' => 'required|numeric|min:0',
            'rate' => 'required|numeric|min:0',
            'item_id' => 'nullable|integer|exists:inventory_items,id',
        ]);
        $data['qty'] = Decimal::of($data['qty'], 3);
        $data['rate'] = Decimal::of($data['rate'], 2);
        $data['amount'] = bcmul($data['qty'], $data['rate'], 2);

        return $data;
    }
}
