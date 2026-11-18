<?php

namespace App\Http\Controllers\Api;

use App\Enums\RequestStatus;
use App\Enums\Role;
use App\Models\ExpenseClaim;
use App\Models\InventoryItem;
use App\Models\MaterialIssueLine;
use App\Models\MaterialRequest;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Chart-ready summaries for the app's Home and Reports screens (`GET /reports?days=30`).
 *
 * Every chart is `{key, title, subtitle, type: donut|bar|column, format: count|money|qty, series: [{label, value, tone}]}`
 * and only charts the user may see are sent, using the same visibility rules as the web lists and reports.
 */
class ReportController extends ApiController
{
    private const PERIODS = [7, 30, 90, 365];

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $days = in_array($request->integer('days'), self::PERIODS, true) ? $request->integer('days') : 30;
        $to = CarbonImmutable::now();
        $from = $to->subDays($days - 1)->startOfDay();
        $showValue = Gate::allows('view-stock-value');

        $kpis = [];
        $charts = [];

        // ---------------------------------------------------------------- stock (every user may view stock)
        $items = InventoryItem::with('category', 'balances')->where('is_active', true)->get();
        $out = $items->filter(fn ($i) => bccomp($i->onHand(), '0', 3) <= 0);
        $low = $items->filter(fn ($i) => $i->isLowStock())->diffKeys($out);
        $inStock = $items->count() - $out->count() - $low->count();

        $kpis[] = ['key' => 'active_items', 'label' => 'Active items', 'value' => $items->count(), 'format' => 'count', 'tone' => 'navy'];
        $kpis[] = ['key' => 'out_of_stock', 'label' => 'Out of stock', 'value' => $out->count(), 'format' => 'count', 'tone' => $out->isNotEmpty() ? 'bad' : 'ok'];
        $kpis[] = ['key' => 'low_stock', 'label' => 'Low stock', 'value' => $low->count(), 'format' => 'count', 'tone' => $low->isNotEmpty() ? 'gold' : 'ok'];

        $charts[] = [
            'key' => 'stock_status', 'title' => 'Stock status', 'subtitle' => 'Active items by stock level, all stores',
            'type' => 'donut', 'format' => 'count',
            'series' => [
                ['label' => 'In stock', 'value' => $inStock, 'tone' => 'ok'],
                ['label' => 'Low stock', 'value' => $low->count(), 'tone' => 'gold'],
                ['label' => 'Out of stock', 'value' => $out->count(), 'tone' => 'bad'],
            ],
        ];

        $byCategory = $items->groupBy(fn ($i) => $i->category?->name ?? 'Uncategorised');
        if ($showValue) {
            $values = $byCategory->map(fn ($group) => $group->reduce(
                fn ($sum, $i) => bcadd($sum, bcmul($i->onHand(), (string) ($i->avg_cost ?? 0), 4), 4), '0'));
            $kpis[] = ['key' => 'stock_value', 'label' => 'Stock value', 'value' => Present::money($values->reduce(fn ($s, $v) => bcadd($s, $v, 4), '0')), 'format' => 'money', 'tone' => 'navy'];
            $charts[] = [
                'key' => 'stock_value_by_category', 'title' => 'Stock value by category', 'subtitle' => 'On hand × average cost',
                'type' => 'bar', 'format' => 'money',
                'series' => $this->top($values->map(fn ($v) => (float) $v), 8)->map(fn ($v, $label) => ['label' => $label, 'value' => Present::money($v)])->values()->all(),
            ];
        } else {
            $charts[] = [
                'key' => 'items_by_category', 'title' => 'Items by category', 'subtitle' => 'Active items in the catalogue',
                'type' => 'bar', 'format' => 'count',
                'series' => $this->top($byCategory->map->count(), 8)->map(fn ($v, $label) => ['label' => $label, 'value' => $v])->values()->all(),
            ];
        }

        // ---------------------------------------------------------------- material requests (what the user may see)
        $requests = MaterialRequest::visibleTo($user)->where('created_at', '>=', $from)->get(['id', 'status', 'created_at']);
        $kpis[] = ['key' => 'requests', 'label' => 'Requests raised', 'value' => $requests->count(), 'format' => 'count', 'tone' => 'navy'];
        $byStatus = $requests->countBy(fn ($r) => $r->status->value);
        $charts[] = [
            'key' => 'requests_by_status', 'title' => 'Requests by status', 'subtitle' => $this->periodLabel($days),
            'type' => 'bar', 'format' => 'count',
            'series' => collect(RequestStatus::cases())
                ->filter(fn ($s) => ($byStatus[$s->value] ?? 0) > 0)
                ->map(fn ($s) => ['label' => $s->label(), 'value' => $byStatus[$s->value], 'tone' => $s->tone()])
                ->values()->all(),
        ];
        $charts[] = [
            'key' => 'requests_over_time', 'title' => 'Requests over time', 'subtitle' => $this->periodLabel($days),
            'type' => 'column', 'format' => 'count',
            'series' => $this->buckets($from, $to, $days)->map(fn ($b) => [
                'label' => $b['label'],
                'value' => $requests->filter(fn ($r) => $r->created_at >= $b['from'] && $r->created_at < $b['to'])->count(),
            ])->all(),
        ];

        // ---------------------------------------------------------------- materials issued (report users only)
        if (Gate::allows('view-reports')) {
            $issued = MaterialIssueLine::query()
                ->join('material_issues', 'material_issues.id', '=', 'material_issue_lines.material_issue_id')
                ->join('inventory_items', 'inventory_items.id', '=', 'material_issue_lines.item_id')
                ->leftJoin('inventory_units', 'inventory_units.id', '=', 'inventory_items.unit_id')
                ->where('material_issues.issued_at', '>=', $from)
                ->tap(fn ($q) => $this->scopeProjects($q, $user, 'material_issues.project_id'));

            $kpis[] = ['key' => 'issues', 'label' => 'Issue vouchers', 'value' => (clone $issued)->distinct()->count('material_issues.id'), 'format' => 'count', 'tone' => 'navy'];
            $charts[] = [
                'key' => 'top_issued_items', 'title' => 'Most issued items', 'subtitle' => 'Quantity issued, '.lcfirst($this->periodLabel($days)),
                'type' => 'bar', 'format' => 'qty',
                'series' => (clone $issued)
                    ->groupBy('inventory_items.id', 'inventory_items.name', 'inventory_units.abbreviation')
                    ->selectRaw('inventory_items.name as name, inventory_units.abbreviation as unit, SUM(material_issue_lines.qty) as qty')
                    ->orderByDesc('qty')->limit(8)->get()
                    ->map(fn ($r) => ['label' => $r->name.($r->unit ? " ({$r->unit})" : ''), 'value' => Present::qty($r->qty)])->all(),
            ];

            if (Gate::allows('view-finance-reports')) {
                $charts[] = [
                    'key' => 'issued_value_by_project', 'title' => 'Materials issued by project', 'subtitle' => 'Value issued, '.lcfirst($this->periodLabel($days)),
                    'type' => 'bar', 'format' => 'money',
                    'series' => (clone $issued)
                        ->join('projects', 'projects.id', '=', 'material_issues.project_id')
                        ->groupBy('projects.id', 'projects.code')
                        ->selectRaw('projects.code as code, SUM(material_issue_lines.line_value) as value')
                        ->orderByDesc('value')->limit(8)->get()
                        ->map(fn ($r) => ['label' => $r->code, 'value' => Present::money($r->value)])->all(),
                ];
            }
        }

        // ---------------------------------------------------------------- expense claims (what the user may see)
        $claims = ExpenseClaim::visibleTo($user)->where('created_at', '>=', $from)->get(['id', 'status', 'amount']);
        if ($claims->isNotEmpty() || $user->hasRole(Role::Admin, Role::OverallManager, Role::ProjectManager, Role::Accountant)) {
            $kpis[] = ['key' => 'claims_amount', 'label' => 'Claims submitted', 'value' => Present::money($claims->reduce(fn ($s, $c) => bcadd($s, (string) $c->amount, 2), '0')), 'format' => 'money', 'tone' => 'gold'];
            $tones = ['submitted' => 'pending', 'approved' => 'partial', 'rejected' => 'rejected', 'paid' => 'approved'];
            $charts[] = [
                'key' => 'claims_by_status', 'title' => 'Expense claims', 'subtitle' => 'Amount by status, '.lcfirst($this->periodLabel($days)),
                'type' => 'donut', 'format' => 'money',
                'series' => collect(ExpenseClaim::STATUSES)->map(fn ($label, $status) => [
                    'label' => $label,
                    'value' => Present::money($claims->where('status', $status)->reduce(fn ($s, $c) => bcadd($s, (string) $c->amount, 2), '0')),
                    'tone' => $tones[$status] ?? 'muted',
                ])->values()->all(),
            ];
        }

        return response()->json([
            'period' => ['days' => $days, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'label' => $this->periodLabel($days)],
            'kpis' => $kpis,
            'charts' => $charts,
        ]);
    }

    /** Project managers see their managed projects only, as on the web reports. */
    private function scopeProjects(Builder $q, $user, string $column): void
    {
        if ($user->role === Role::ProjectManager) {
            $q->whereIn($column, Project::where('manager_id', $user->id)->select('id'));
        }
    }

    /** The largest $n entries; the rest are summed into "Other". */
    private function top($values, int $n)
    {
        $sorted = $values->filter(fn ($v) => $v > 0)->sortDesc();
        if ($sorted->count() <= $n) {
            return $sorted;
        }

        return $sorted->take($n - 1)->put('Other', $sorted->slice($n - 1)->sum());
    }

    /** Daily buckets for a week, weekly for a month, monthly otherwise. */
    private function buckets(CarbonImmutable $from, CarbonImmutable $to, int $days)
    {
        $out = collect();
        if ($days <= 7) {
            for ($d = $from; $d <= $to; $d = $d->addDay()) {
                $out->push(['label' => $d->format('D'), 'from' => $d, 'to' => $d->addDay()]);
            }
        } elseif ($days <= 31) {
            for ($d = $from; $d <= $to; $d = $d->addWeek()) {
                $out->push(['label' => $d->format('j M'), 'from' => $d, 'to' => $d->addWeek()]);
            }
        } else {
            for ($d = $from->startOfMonth(); $d <= $to; $d = $d->addMonth()) {
                $out->push(['label' => $d->format('M y'), 'from' => $d->max($from), 'to' => $d->addMonth()]);
            }
        }

        return $out;
    }

    private function periodLabel(int $days): string
    {
        return match ($days) {
            7 => 'Last 7 days',
            30 => 'Last 30 days',
            90 => 'Last 90 days',
            365 => 'Last 12 months',
            default => "Last {$days} days",
        };
    }
}
