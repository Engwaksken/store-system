<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\MaterialIssueLine;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\StockMovement;
use App\Services\ProjectCostService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every report renders as a printable HTML table, or as CSV with ?format=csv.
 * Rows are scoped to what the user may see (PMs: their managed projects only).
 */
class ReportController extends Controller
{
    public function index()
    {
        Gate::authorize('view-reports');

        return view('reports.index');
    }

    public function stock(Request $request)
    {
        Gate::authorize('view-reports');
        $showValue = Gate::allows('view-stock-value');
        $rows = InventoryItem::with('category', 'unit', 'balance')->orderBy('name')->get()->map(function ($i) use ($showValue) {
            $row = [$i->sku, $i->name, $i->category->name, $i->unit->abbreviation, $i->onHand(), (string) $i->min_level, $i->isLowStock() ? 'LOW' : ''];
            if ($showValue) {
                $row[] = (string) $i->avg_cost;
                $row[] = bcmul($i->onHand(), (string) $i->avg_cost, 2);
            }

            return $row;
        });
        $headers = ['SKU', 'Item', 'Category', 'Unit', 'On hand', 'Min level', 'Status'];
        if ($showValue) {
            array_push($headers, 'Avg cost (UGX)', 'Stock value (UGX)');
        }

        return $this->respond($request, 'Stock summary', $headers, $rows, numeric: [4, 5, 7, 8], totals: $showValue ? [8] : [], money: [7, 8]);
    }

    public function movements(Request $request)
    {
        Gate::authorize('view-stock-ledger');
        $q = StockMovement::with('item.unit', 'project', 'actor')
            ->when($request->filled('item_id'), fn ($q) => $q->where('item_id', $request->integer('item_id')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('movement_type', $request->type))
            ->tap(fn ($q) => $this->dateRange($q, $request, 'created_at'))
            ->orderBy('id');
        $rows = $q->limit(5000)->get()->map(fn ($m) => [
            $m->created_at->format('Y-m-d H:i'), $m->item->sku, $m->item->name, $m->movement_type,
            (string) $m->qty_delta, (string) $m->balance_after, (string) $m->unit_cost,
            $m->project?->code ?? '', strtoupper(str_replace('_', ' ', $m->source_type)).' #'.$m->source_id, $m->actor->name,
        ]);

        return $this->respond($request, 'Stock movement ledger', ['Date', 'SKU', 'Item', 'Type', 'Qty change', 'Balance after', 'Unit cost', 'Project', 'Source', 'By'],
            $rows, numeric: [4, 5, 6], filters: ['item', 'project', 'type', 'dates'], money: [6]);
    }

    public function pending(Request $request)
    {
        Gate::authorize('view-reports');
        $rows = MaterialRequest::visibleTo($request->user())->with('project', 'requester', 'projectManager')
            ->whereIn('status', [RequestStatus::Submitted->value, RequestStatus::PmApproved->value, RequestStatus::OverallApproved->value, RequestStatus::PartiallyIssued->value])
            ->oldest('submitted_at')->get()
            ->map(fn ($r) => [
                $r->request_no, 'v'.$r->version, $r->project->code, $r->requester->name, $r->status->label(),
                $r->status === RequestStatus::Submitted ? ($r->projectManager?->name ?? '-') : ($r->status === RequestStatus::PmApproved ? 'Overall Manager' : 'Storekeeper'),
                $r->submitted_at?->format('Y-m-d H:i') ?? '', $r->submitted_at ? (string) $r->submitted_at->diffInDays(now(), true) : '',
            ]);

        return $this->respond($request, 'Pending approvals & issues', ['Request', 'Ver.', 'Project', 'Requester', 'Status', 'Waiting on', 'Submitted', 'Days waiting'], $rows, numeric: [7]);
    }

    public function issued(Request $request)
    {
        Gate::authorize('view-finance-reports');
        $q = MaterialIssueLine::query()
            ->join('material_issues', 'material_issues.id', '=', 'material_issue_lines.material_issue_id')
            ->join('projects', 'projects.id', '=', 'material_issues.project_id')
            ->join('inventory_items', 'inventory_items.id', '=', 'material_issue_lines.item_id')
            ->tap(fn ($q) => $this->scopeProjects($q, $request, 'material_issues.project_id'))
            ->tap(fn ($q) => $this->dateRange($q, $request, 'material_issues.issued_at'))
            ->groupBy('projects.code', 'projects.name', 'inventory_items.sku', 'inventory_items.name')
            ->orderBy('projects.code')->orderBy('inventory_items.name')
            ->selectRaw('projects.code, projects.name as project_name, inventory_items.sku, inventory_items.name as item_name, SUM(material_issue_lines.qty) as qty, SUM(material_issue_lines.line_value) as value');
        $rows = $q->get()->map(fn ($r) => [$r->code, $r->project_name, $r->sku, $r->item_name, bcadd((string) $r->qty, '0', 3), bcadd((string) $r->value, '0', 2)]);

        return $this->respond($request, 'Materials issued by project', ['Project', 'Project name', 'SKU', 'Item', 'Qty issued', 'Value (UGX)'],
            $rows, numeric: [4, 5], totals: [5], filters: ['project', 'dates'], money: [5]);
    }

    public function expenses(Request $request)
    {
        Gate::authorize('view-finance-reports');
        $q = Expense::query()
            ->join('projects', 'projects.id', '=', 'expenses.project_id')
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
            ->tap(fn ($q) => $this->scopeProjects($q, $request, 'expenses.project_id'))
            ->tap(fn ($q) => $this->dateRange($q, $request, 'expenses.incurred_on'))
            ->groupBy('projects.code', 'projects.name', 'expense_categories.name')
            ->orderBy('projects.code')->orderBy('expense_categories.name')
            ->selectRaw('projects.code, projects.name as project_name, expense_categories.name as category, COUNT(*) as n, SUM(expenses.amount) as total');
        $rows = $q->get()->map(fn ($r) => [$r->code, $r->project_name, $r->category, (string) $r->n, bcadd((string) $r->total, '0', 2)]);

        return $this->respond($request, 'Cash expenses by project', ['Project', 'Project name', 'Category', 'Entries', 'Amount (UGX)'],
            $rows, numeric: [3, 4], totals: [4], filters: ['project', 'dates'], money: [4]);
    }

    public function projectCost(Request $request, ProjectCostService $costs)
    {
        Gate::authorize('view-finance-reports');
        $projects = Project::query()->tap(fn ($q) => $this->scopeProjects($q, $request, 'projects.id'))->orderBy('code')->get();
        $rows = $costs->summary($projects)->map(fn ($c) => [
            $c->project->code, $c->project->name, $c->project->status, $c->budget, $c->materials, $c->expenses, $c->total, $c->remaining,
            $c->used_pct === null ? '' : $c->used_pct.'%',
        ]);

        return $this->respond($request, 'Project budget vs cost', ['Code', 'Project', 'Status', 'Budget', 'Materials issued', 'Cash expenses', 'Total cost', 'Remaining', 'Used'],
            $rows, numeric: [3, 4, 5, 6, 7], totals: [3, 4, 5, 6, 7], filters: ['project'], money: [3, 4, 5, 6, 7],
            note: 'Total cost = materials issued from store (valued at average cost when issued) + cash expenses. Store purchases are not project cost until issued, so they are never counted twice.');
    }

    // ---------------------------------------------------------------- helpers

    private function scopeProjects(Builder $q, Request $request, string $column): void
    {
        $user = $request->user();
        if ($user->role === Role::ProjectManager) {
            $q->whereIn($column, Project::where('manager_id', $user->id)->select('id'));
        }
        if ($request->filled('project_id')) {
            $q->where($column, $request->integer('project_id'));
        }
    }

    private function dateRange(Builder $q, Request $request, string $column): void
    {
        if ($request->filled('from')) {
            $q->whereDate($column, '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate($column, '<=', $request->date('to'));
        }
    }

    private function respond(Request $request, string $title, array $headers, $rows, array $numeric = [], array $totals = [], array $filters = [], ?string $note = null, array $money = [])
    {
        $rows = collect($rows)->values();
        $sum = [];
        foreach ($totals as $i) {
            $sum[$i] = $rows->reduce(fn ($c, $r) => bcadd($c, (string) ($r[$i] ?: '0'), 2), '0');
        }

        if ($request->query('format') === 'csv') {
            return $this->csv(str($title)->slug().'-'.now()->format('Ymd-His').'.csv', $headers, $rows, $sum);
        }

        $user = $request->user();

        return view('reports.table', [
            'title' => $title, 'headers' => $headers, 'rows' => $rows, 'numeric' => $numeric, 'money' => $money, 'sum' => $sum,
            'filters' => $filters, 'note' => $note,
            'projects' => in_array('project', $filters) ? Project::visibleTo($user)
                ->when($user->role === Role::ProjectManager, fn ($q) => $q->where('manager_id', $user->id))->orderBy('code')->get() : collect(),
            'items' => in_array('item', $filters) ? InventoryItem::orderBy('name')->get() : collect(),
        ]);
    }

    private function csv(string $filename, array $headers, $rows, array $sum): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $sum) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it correctly
            fputcsv($out, $headers);
            foreach ($rows as $r) {
                // Neutralise spreadsheet formula injection.
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) && ! is_numeric($v) ? "'".$v : $v, $r));
            }
            if ($sum) {
                $line = array_fill(0, count($headers), '');
                $line[0] = 'TOTAL';
                foreach ($sum as $i => $v) {
                    $line[$i] = $v;
                }
                fputcsv($out, $line);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
