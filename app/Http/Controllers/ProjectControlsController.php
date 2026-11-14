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
            $msg .= ' Note: cost-code budgets total '.ugx($total).' '.currency().' but the project budget is '.ugx($project->budget).' '.currency().'.';
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

    public function boqTemplate(Project $project)
    {
        Gate::authorize('manage-project-controls', $project);

        return \App\Support\CsvImport::template("boq-{$project->code}.csv", ['ref', 'description', 'cost_code', 'unit', 'qty', 'rate', 'sku'],
            [['3.1', 'Ground floor slab 150mm C25', CostCode::where('is_active', true)->orderBy('code')->value('code') ?? '03-CON', 'm3', '45', '650000', '']]);
    }

    /** All-or-nothing BOQ import: cost code by code, optional catalogue SKU; refs must be new for the project. */
    public function importBoq(Request $request, Project $project)
    {
        Gate::authorize('manage-project-controls', $project);
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);
        [$rows, $error] = \App\Support\CsvImport::read($request->file('file'), ['ref', 'description', 'cost_code', 'unit', 'qty', 'rate']);
        if ($error) {
            return back()->withErrors(['file' => $error]);
        }
        $codes = CostCode::where('is_active', true)->pluck('id', 'code')->mapWithKeys(fn ($id, $c) => [mb_strtolower($c) => $id]);
        $skus = InventoryItem::pluck('id', 'sku')->mapWithKeys(fn ($id, $s) => [mb_strtolower($s) => $id]);
        $existing = $project->boqItems()->pluck('ref')->map(fn ($r) => mb_strtolower($r))->flip();
        $seen = $errors = $new = [];
        foreach ($rows as $line => $r) {
            $ref = $r['ref'];
            $qty = \App\Support\CsvImport::number($r['qty']);
            $rate = \App\Support\CsvImport::number($r['rate']);
            $sku = mb_strtolower($r['sku'] ?? '');
            if ($ref === '' || $r['description'] === '' || $r['unit'] === '') {
                $errors[] = "Row {$line}: ref, description and unit are required.";
            } elseif (isset($existing[mb_strtolower($ref)]) || isset($seen[mb_strtolower($ref)])) {
                $errors[] = "Row {$line}: BOQ ref {$ref} already exists on this project.";
            } elseif (! isset($codes[mb_strtolower($r['cost_code'])])) {
                $errors[] = "Row {$line}: unknown or inactive cost code '{$r['cost_code']}'.";
            } elseif ($qty === null || $rate === null) {
                $errors[] = "Row {$line}: qty and rate must be numbers of 0 or more.";
            } elseif ($sku !== '' && ! isset($skus[$sku])) {
                $errors[] = "Row {$line}: unknown SKU '{$r['sku']}'.";
            } else {
                $seen[mb_strtolower($ref)] = true;
                $q = Decimal::of($qty, 3);
                $rt = Decimal::of($rate, 2);
                $new[] = ['project_id' => $project->id, 'ref' => mb_substr($ref, 0, 30), 'description' => mb_substr($r['description'], 0, 500),
                    'cost_code_id' => $codes[mb_strtolower($r['cost_code'])], 'unit' => mb_substr($r['unit'], 0, 20), 'qty' => $q, 'rate' => $rt,
                    'amount' => bcmul($q, $rt, 2), 'item_id' => $sku !== '' ? $skus[$sku] : null];
            }
        }
        if ($errors) {
            return back()->withErrors(array_slice($errors, 0, 20));
        }
        DB::transaction(fn () => collect($new)->each(fn ($d) => BoqItem::create($d)));
        ActivityLog::record('boq.imported', count($new)." BOQ lines imported into {$project->code}", $project);

        return redirect()->route('projects.controls', $project)->with('status', count($new).' BOQ lines imported.');
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
