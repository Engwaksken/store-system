<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\MaterialIssueLine;
use App\Models\MaterialRequest;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\StockReturnLine;
use App\Services\ProjectCostService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use App\Support\Pdf;
use App\Support\XlsxWriter;
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
        $storeId = $request->filled('store_id') ? $request->integer('store_id') : null;
        $rows = InventoryItem::with('category', 'unit', 'balances')->orderBy('name')->get()->map(function ($i) use ($showValue, $storeId) {
            $row = [$i->sku, $i->name, $i->category->name, $i->unit->abbreviation, $i->onHand($storeId), (string) $i->min_level, $i->isLowStock() ? 'LOW' : ''];
            if ($showValue) {
                $row[] = (string) $i->avg_cost;
                $row[] = bcmul($i->onHand($storeId), (string) $i->avg_cost, 2);
            }

            return $row;
        });
        $headers = ['SKU', 'Item', 'Category', 'Unit', 'On hand', 'Min level', 'Status'];
        if ($showValue) {
            array_push($headers, 'Avg cost ('.currency().')', 'Stock value ('.currency().')');
        }

        return $this->respond($request, 'Stock summary', $headers, $rows, numeric: [4, 5, 7, 8], totals: $showValue ? [8] : [], money: [7, 8], filters: ['store'],
            note: 'Without a store filter, quantities are company-wide. Minimum level and LOW status always use company-wide stock.');
    }

    public function movements(Request $request)
    {
        Gate::authorize('view-stock-ledger');
        $q = StockMovement::with('item.unit', 'project', 'actor', 'store')
            ->when($request->filled('store_id'), fn ($q) => $q->where('store_id', $request->integer('store_id')))
            ->when($request->filled('item_id'), fn ($q) => $q->where('item_id', $request->integer('item_id')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->integer('project_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('movement_type', $request->type))
            ->tap(fn ($q) => $this->dateRange($q, $request, 'created_at'))
            ->orderBy('id');
        $rows = $q->limit(5000)->get()->map(fn ($m) => [
            $m->created_at->format('Y-m-d H:i'), $m->store->code, $m->item->sku, $m->item->name, $m->movement_type,
            (string) $m->qty_delta, (string) $m->balance_after, (string) $m->unit_cost,
            $m->project?->code ?? '', strtoupper(str_replace('_', ' ', $m->source_type)).' #'.$m->source_id, $m->actor->name,
        ]);

        return $this->respond($request, 'Stock movement ledger', ['Date', 'Store', 'SKU', 'Item', 'Type', 'Qty change', 'Store balance after', 'Unit cost', 'Project', 'Source', 'By'],
            $rows, numeric: [5, 6, 7], filters: ['store', 'item', 'project', 'type', 'dates'], money: [7]);
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
        $returned = StockReturnLine::query()
            ->join('stock_returns', 'stock_returns.id', '=', 'stock_return_lines.stock_return_id')
            ->join('projects', 'projects.id', '=', 'stock_returns.project_id')
            ->join('inventory_items', 'inventory_items.id', '=', 'stock_return_lines.item_id')
            ->tap(fn ($q) => $this->scopeProjects($q, $request, 'stock_returns.project_id'))
            ->tap(fn ($q) => $this->dateRange($q, $request, 'stock_returns.returned_at'))
            ->groupBy('projects.code', 'inventory_items.sku')
            ->selectRaw('projects.code, inventory_items.sku, SUM(stock_return_lines.qty) as qty, SUM(stock_return_lines.line_value) as value')
            ->get()->keyBy(fn ($r) => $r->code.'|'.$r->sku);
        $rows = $q->get()->map(function ($r) use ($returned) {
            $ret = $returned->get($r->code.'|'.$r->sku);
            $rq = bcadd((string) ($ret->qty ?? 0), '0', 3);
            $rv = bcadd((string) ($ret->value ?? 0), '0', 2);
            $v = bcadd((string) $r->value, '0', 2);

            return [$r->code, $r->project_name, $r->sku, $r->item_name, bcadd((string) $r->qty, '0', 3), $v, $rq, $rv, bcsub($v, $rv, 2)];
        });

        return $this->respond($request, 'Materials issued by project', ['Project', 'Project name', 'SKU', 'Item', 'Qty issued', 'Issued value', 'Qty returned', 'Returned value', 'Net cost ('.currency().')'],
            $rows, numeric: [4, 5, 6, 7, 8], totals: [5, 7, 8], filters: ['project', 'dates'], money: [5, 7, 8],
            note: 'Returns are credited at the cost on the original voucher. Returns dated outside the filter period are not shown.');
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

        return $this->respond($request, 'Cash expenses by project', ['Project', 'Project name', 'Category', 'Entries', 'Amount ('.currency().')'],
            $rows, numeric: [3, 4], totals: [4], filters: ['project', 'dates'], money: [4]);
    }

    public function projectCost(Request $request, ProjectCostService $costs)
    {
        Gate::authorize('view-finance-reports');
        $projects = Project::query()->tap(fn ($q) => $this->scopeProjects($q, $request, 'projects.id'))->orderBy('code')->get();
        $rows = $costs->summary($projects)->map(fn ($c) => [
            $c->project->code, $c->project->name, $c->project->status, $c->budget, $c->materials, $c->expenses, $c->total, $c->committed, $c->uncommitted,
            $c->exposure_pct === null ? '' : $c->exposure_pct.'%', strtoupper($c->flag === 'ok' ? '' : $c->flag),
        ]);

        return $this->respond($request, 'Project budget vs cost', ['Code', 'Project', 'Status', 'Budget', 'Materials (net)', 'Cash expenses', 'Actual cost', 'Committed', 'Uncommitted budget', 'Actual+committed', 'Flag'],
            $rows, numeric: [3, 4, 5, 6, 7, 8], totals: [3, 4, 5, 6, 7, 8], filters: ['project'], money: [3, 4, 5, 6, 7, 8],
            note: 'Actual = materials issued (net of returns, at issue cost) + cash expenses. Committed = approved POs not yet received + approved claims not yet paid. Store purchases are not project cost until issued, so they are never counted twice. WARN from '.ProjectCostService::WARN_PCT.'%, OVER above 100%.');
    }

    /** Budget vs committed vs actual by cost code, one block of rows per project. */
    public function costCodes(Request $request, ProjectCostService $costs)
    {
        Gate::authorize('view-finance-reports');
        $projects = Project::query()->tap(fn ($q) => $this->scopeProjects($q, $request, 'projects.id'))->orderBy('code')->get();
        $rows = $projects->flatMap(fn ($p) => $costs->byCostCode($p)->map(fn ($r) => [
            $p->code, $r->label, $r->budget, $r->materials, $r->expenses, $r->actual, $r->committed, $r->variance,
            $r->pct === null ? '' : $r->pct.'%', strtoupper($r->flag === 'ok' ? '' : $r->flag),
        ]));

        return $this->respond($request, 'Budget vs committed vs actual by cost code', ['Project', 'Cost code', 'Budget', 'Materials', 'Expenses', 'Actual', 'Committed', 'Variance', 'Used', 'Flag'],
            $rows, numeric: [2, 3, 4, 5, 6, 7], totals: [2, 3, 4, 5, 6, 7], filters: ['project'], money: [2, 3, 4, 5, 6, 7],
            note: 'Variance = budget - (actual + committed). Materials are allocated through the BOQ line chosen on the material request; issues without a BOQ line appear as Unallocated.');
    }

    public function boqConsumption(Request $request, ProjectCostService $costs)
    {
        Gate::authorize('view-finance-reports');
        $projects = Project::query()->tap(fn ($q) => $this->scopeProjects($q, $request, 'projects.id'))->orderBy('code')->get();
        $rows = $projects->flatMap(fn ($p) => $costs->boqConsumption($p)->map(fn ($r) => [
            $p->code, $r->boq->ref, $r->boq->description, $r->boq->costCode->code, (string) $r->boq->qty, $r->boq->unit,
            (string) $r->boq->amount, $r->consumed_value, $r->pct === null ? '' : $r->pct.'%',
        ]));

        return $this->respond($request, 'Material consumption per BOQ line', ['Project', 'Ref', 'Description', 'Cost code', 'BOQ qty', 'Unit', 'BOQ amount', 'Materials used (net)', 'Used'],
            $rows, numeric: [4, 6, 7], totals: [6, 7], filters: ['project'], money: [6, 7]);
    }

    /** Open commitments: approved POs not fully received, and approved claims not yet paid. */
    public function commitments(Request $request)
    {
        Gate::authorize('view-finance-reports');
        $pos = PurchaseOrder::with('project', 'supplier', 'costCode', 'lines')->whereIn('status', PurchaseOrder::RECEIVABLE)->whereNotNull('project_id')
            ->tap(fn ($q) => $this->scopeProjects($q, $request, 'project_id'))->orderBy('id')->get()
            ->map(fn ($po) => [$po->project->code, 'Purchase order', $po->po_no, $po->supplier->name, $po->costCode?->code ?? '', $po->order_date->format('Y-m-d'), $po->openValue()]);
        $claims = \App\Models\ExpenseClaim::with('project', 'claimant', 'costCode')->where('status', 'approved')
            ->tap(fn ($q) => $this->scopeProjects($q, $request, 'project_id'))->orderBy('id')->get()
            ->map(fn ($c) => [$c->project->code, 'Approved claim', $c->claim_no, $c->claimant->name, $c->costCode?->code ?? '', $c->incurred_on->format('Y-m-d'), (string) $c->amount]);

        return $this->respond($request, 'Open commitments', ['Project', 'Type', 'Document', 'Supplier / claimant', 'Cost code', 'Date', 'Open value ('.currency().')'],
            $pos->concat($claims)->sortBy(0)->values(), numeric: [6], totals: [6], filters: ['project'], money: [6]);
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

    /** Period preset (this month, last quarter ...) or custom from/to, shared with the list pages. */
    private function dateRange(Builder $q, Request $request, string $column): void
    {
        [$from, $to] = \App\Support\ListPage::rangeFor($request);
        if ($from) {
            $q->whereDate($column, '>=', $from->toDateString());
        }
        if ($to) {
            $q->whereDate($column, '<=', $to->toDateString());
        }
    }

    private function respond(Request $request, string $title, array $headers, $rows, array $numeric = [], array $totals = [], array $filters = [], ?string $note = null, array $money = [])
    {
        $rows = collect($rows)->values();
        $sum = [];
        foreach ($totals as $i) {
            $sum[$i] = $rows->reduce(fn ($c, $r) => bcadd($c, (string) ($r[$i] ?: '0'), 2), '0');
        }

        $file = str($title)->slug().'-'.now()->format('Ymd-His');
        switch ($request->query('format')) {
            case 'csv':
                return $this->csv($file.'.csv', $headers, $rows, $sum);
            case 'xlsx':
                $totals = null;
                if ($sum) {
                    $totals = array_fill(0, count($headers), '');
                    $totals[0] = 'TOTAL';
                    foreach ($sum as $i => $v) {
                        $totals[$i] = $v;
                    }
                }
                $path = XlsxWriter::write($title, $headers, $rows, $numeric, $totals);

                return response()->download($path, $file.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
            case 'pdf':
                [$from, $to] = \App\Support\ListPage::rangeFor($request);
                $filterText = collect($request->only(['store_id', 'project_id', 'item_id', 'type']))->filter()
                    ->map(fn ($v, $k) => str_replace('_id', '', $k).': '.$v)
                    ->when($from || $to, fn ($c) => $c->prepend('Period: '.($from?->format('d M Y') ?? 'start').' - '.($to?->format('d M Y') ?? 'today')))
                    ->implode(' · ');

                return Pdf::download('pdf.report', compact('title', 'headers', 'rows', 'numeric', 'money', 'sum', 'note', 'filterText'), $file.'.pdf',
                    count($headers) > 7 ? 'landscape' : 'portrait');
        }

        $user = $request->user();

        return view('reports.table', [
            'title' => $title, 'headers' => $headers, 'rows' => $rows, 'numeric' => $numeric, 'money' => $money, 'sum' => $sum,
            'filters' => $filters, 'note' => $note,
            'projects' => in_array('project', $filters) ? Project::visibleTo($user)
                ->when($user->role === Role::ProjectManager, fn ($q) => $q->where('manager_id', $user->id))->orderBy('code')->get() : collect(),
            'items' => in_array('item', $filters) ? InventoryItem::orderBy('name')->get() : collect(),
            'stores' => in_array('store', $filters) ? \App\Models\Store::orderBy('id')->get() : collect(),
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
