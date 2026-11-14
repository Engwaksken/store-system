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
    /** Report groups, in display order, for the landing page. */
    public const GROUPS = [
        'stock' => ['Stock', 'fa-boxes-stacked', 'What is in the stores and how it moved.'],
        'procurement' => ['Procurement & approvals', 'fa-clipboard-check', 'Requests in the pipeline and money already promised.'],
        'finance' => ['Project finance', 'fa-chart-pie', 'Spend against budget, by project, cost code and BOQ line.'],
    ];

    /**
     * Presentation only: name, one-line description, icon, landing-page group and gate of each report.
     * The gate mirrors the Gate::authorize() call in the report's action, so the landing page lists exactly
     * the reports the user may open.
     */
    public const CATALOG = [
        'reports.stock' => ['Stock summary', 'Balances, minimum levels and stock value per item.', 'fa-boxes-stacked', 'stock', 'view-reports'],
        'reports.movements' => ['Stock movement ledger', 'Every receipt, issue, transfer and adjustment, with the running store balance.', 'fa-right-left', 'stock', 'view-stock-ledger'],
        'reports.pending' => ['Pending approvals & issues', 'Who each open request is waiting on, and for how long.', 'fa-hourglass-half', 'procurement', 'view-reports'],
        'reports.commitments' => ['Open commitments', 'Approved POs not yet received and approved claims not yet paid.', 'fa-file-signature', 'procurement', 'view-finance-reports'],
        'reports.project-cost' => ['Project budget vs cost', 'Budget, actual and committed cost per project, with over-budget flags.', 'fa-scale-balanced', 'finance', 'view-finance-reports'],
        'reports.cost-codes' => ['Budget by cost code', 'Budget vs committed vs actual, and the variance, per cost code.', 'fa-sitemap', 'finance', 'view-finance-reports'],
        'reports.boq' => ['Consumption per BOQ line', 'Materials used against each bill-of-quantities line.', 'fa-list-check', 'finance', 'view-finance-reports'],
        'reports.issued' => ['Materials issued by project', 'Quantities and value issued, less returns, per project and item.', 'fa-truck-ramp-box', 'finance', 'view-finance-reports'],
        'reports.expenses' => ['Cash expenses by project', 'Cash spend per project and expense category.', 'fa-money-bill-wave', 'finance', 'view-finance-reports'],
    ];

    /** Screen-only column hints (exports are unchanged): headers shown as badges, and percentage headers shown with a bar. */
    private const BADGE_COLUMNS = ['Status', 'Flag'];
    private const PERCENT_COLUMNS = ['Used', 'Actual+committed'];

    public function index()
    {
        Gate::authorize('view-reports');

        $reports = collect(self::CATALOG)
            ->filter(fn ($r) => Gate::allows($r[4]))
            ->map(fn ($r, $route) => ['route' => $route, 'name' => $r[0], 'description' => $r[1], 'icon' => $r[2], 'group' => $r[3]])
            ->groupBy('group');

        return view('reports.index', [
            'groups' => collect(self::GROUPS)->filter(fn ($g, $key) => $reports->has($key))
                ->map(fn ($g, $key) => ['name' => $g[0], 'icon' => $g[1], 'description' => $g[2], 'reports' => $reports[$key]]),
        ]);
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

        $route = $request->route()?->getName();
        $meta = self::CATALOG[$route] ?? null;

        return view('reports.table', [
            'title' => $title, 'headers' => $headers, 'rows' => $rows, 'numeric' => $numeric, 'money' => $money, 'sum' => $sum,
            'filters' => $filters, 'note' => $note,
            // Presentation only, derived from the rows above.
            'description' => $meta[1] ?? null, 'icon' => $meta[2] ?? 'fa-table',
            'badgeCols' => array_keys(array_intersect($headers, self::BADGE_COLUMNS)),
            'percentCols' => array_keys(array_intersect($headers, self::PERCENT_COLUMNS)),
            'kpis' => $this->kpis($route, $headers, $rows, $sum),
            'projects' => in_array('project', $filters) ? Project::visibleTo($user)
                ->when($user->role === Role::ProjectManager, fn ($q) => $q->where('manager_id', $user->id))->orderBy('code')->get() : collect(),
            'items' => in_array('item', $filters) ? InventoryItem::orderBy('name')->get() : collect(),
            'stores' => in_array('store', $filters) ? \App\Models\Store::orderBy('id')->get() : collect(),
        ]);
    }

    /**
     * Headline figures for the strip above a report table, computed only from the rows already fetched
     * (so they always agree with the table and the exports). Each card: [label, value, icon, tone].
     */
    private function kpis(?string $route, array $headers, $rows, array $sum): array
    {
        $n = $rows->count();
        $col = fn (int $i) => $rows->pluck($i);
        $total = fn (int $i) => $sum[$i] ?? $col($i)->reduce(fn ($c, $v) => bcadd($c, is_numeric($v) ? (string) $v : '0', 2), '0');
        $count = fn (int $i, string ...$values) => $col($i)->filter(fn ($v) => in_array($v, $values, true))->count();
        $cur = currency();

        switch ($route) {
            case 'reports.stock':
                $low = $count(6, 'LOW');

                return array_values(array_filter([
                    ['Items', number_format($n), 'fa-boxes-stacked', 'navy'],
                    ['Below minimum level', number_format($low), 'fa-triangle-exclamation', $low ? 'bad' : ''],
                    count($headers) > 8 ? ['Stock value ('.$cur.')', ugx($total(8)), 'fa-coins', 'gold'] : null,
                ]));
            case 'reports.movements':
                return [
                    ['Movements', number_format($n).($n >= 5000 ? ' (limit)' : ''), 'fa-right-left', 'navy'],
                    ['Items moved', number_format($col(2)->unique()->count()), 'fa-cubes', ''],
                    ['Stores', number_format($col(1)->unique()->count()), 'fa-warehouse', 'gold'],
                ];
            case 'reports.pending':
                $days = $col(7)->filter(fn ($v) => $v !== '')->map(fn ($v) => (float) $v);
                $late = $days->filter(fn ($d) => $d > 7)->count();

                return [
                    ['Open requests', number_format($n), 'fa-inbox', 'navy'],
                    ['Average wait (days)', $days->count() ? number_format($days->avg(), 1) : '-', 'fa-hourglass-half', 'gold'],
                    ['Longest wait (days)', $days->count() ? number_format($days->max()) : '-', 'fa-clock', ''],
                    ['Waiting over 7 days', number_format($late), 'fa-triangle-exclamation', $late ? 'bad' : ''],
                ];
            case 'reports.issued':
                return [
                    ['Projects', number_format($col(0)->unique()->count()), 'fa-helmet-safety', 'navy'],
                    ['Issued value ('.$cur.')', ugx($total(5)), 'fa-truck-ramp-box', ''],
                    ['Returned value ('.$cur.')', ugx($total(7)), 'fa-rotate-left', 'gold'],
                    ['Net cost ('.$cur.')', ugx($total(8)), 'fa-coins', 'navy'],
                ];
            case 'reports.expenses':
                return [
                    ['Projects', number_format($col(0)->unique()->count()), 'fa-helmet-safety', 'navy'],
                    ['Expense entries', number_format($col(3)->sum(fn ($v) => (int) $v)), 'fa-receipt', ''],
                    ['Total amount ('.$cur.')', ugx($total(4)), 'fa-money-bill-wave', 'gold'],
                ];
            case 'reports.project-cost':
                $over = $count(10, 'OVER');

                return [
                    ['Budget ('.$cur.')', ugx($total(3)), 'fa-wallet', 'navy'],
                    ['Actual cost ('.$cur.')', ugx($total(6)), 'fa-coins', ''],
                    ['Committed ('.$cur.')', ugx($total(7)), 'fa-file-signature', 'gold'],
                    ['Over / near budget', number_format($over).' / '.number_format($count(10, 'WARN')), 'fa-triangle-exclamation', $over ? 'bad' : ''],
                ];
            case 'reports.cost-codes':
                return [
                    ['Budget ('.$cur.')', ugx($total(2)), 'fa-wallet', 'navy'],
                    ['Actual ('.$cur.')', ugx($total(5)), 'fa-coins', ''],
                    ['Committed ('.$cur.')', ugx($total(6)), 'fa-file-signature', 'gold'],
                    ['Variance ('.$cur.')', ugx($total(7)), 'fa-scale-balanced', bccomp($total(7), '0', 2) < 0 ? 'bad' : 'navy'],
                ];
            case 'reports.boq':
                $amount = $total(6);

                return [
                    ['BOQ lines', number_format($n), 'fa-list-check', 'navy'],
                    ['BOQ amount ('.$cur.')', ugx($amount), 'fa-wallet', ''],
                    ['Materials used ('.$cur.')', ugx($total(7)), 'fa-cubes', 'gold'],
                    ['Overall used', bccomp($amount, '0', 2) > 0 ? number_format((float) bcdiv(bcmul($total(7), '100', 2), $amount, 2), 1).'%' : '-', 'fa-percent', ''],
                ];
            case 'reports.commitments':
                $sumOf = fn ($c) => $c->reduce(fn ($t, $r) => bcadd($t, (string) ($r[6] ?: '0'), 2), '0');

                return [
                    ['Open documents', number_format($n), 'fa-file-lines', 'navy'],
                    ['Open POs ('.$cur.')', ugx($sumOf($rows->where(1, 'Purchase order'))), 'fa-cart-shopping', ''],
                    ['Approved claims ('.$cur.')', ugx($sumOf($rows->where(1, 'Approved claim'))), 'fa-receipt', 'gold'],
                    ['Total committed ('.$cur.')', ugx($total(6)), 'fa-file-signature', 'navy'],
                ];
        }

        return [['Rows', number_format($n), 'fa-table', 'navy']];
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
