<?php

namespace App\Support;

use App\Models\Project;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared behaviour of every list page: period / project / store / search filters, pagination,
 * and CSV / PDF export of exactly the filtered rows.
 *
 *   return ListPage::make($request, $query, 'Material requests')
 *       ->period('created_at')->project('project_id')->search(['request_no', 'purpose'])
 *       ->columns(['Request' => fn ($r) => $r->request_no, 'Value' => [fn ($r) => $r->total, 'money']])
 *       ->respond(fn ($rows, $list) => view('requests.index', compact('rows', 'list')));
 *
 * The caller passes a query that is already limited to what the user may see; filters only narrow it.
 */
class ListPage
{
    public const PERIODS = [
        '' => 'All time', 'today' => 'Today', 'yesterday' => 'Yesterday', 'this_week' => 'This week', 'last_week' => 'Last week',
        'this_month' => 'This month', 'last_month' => 'Last month', 'last_30' => 'Last 30 days', 'this_quarter' => 'This quarter',
        'this_year' => 'This year', 'last_year' => 'Last year', 'custom' => 'Custom dates',
    ];

    public const EXPORT_LIMIT = 5000;

    private ?Closure $periodFilter = null;
    private ?Closure $projectFilter = null;
    private ?Closure $storeFilter = null;
    private ?Closure $searchFilter = null;
    private ?Closure $afterQuery = null;
    private array $columns = [];
    private array $totals = [];
    private int $perPage = 25;
    private bool $applied = false;
    public ?string $periodLabel = null;
    public string $storeLabel = 'Store';
    public ?string $searchPlaceholder = null;

    private function __construct(public Request $request, private Builder $query, public string $title)
    {
    }

    public static function make(Request $request, Builder $query, string $title): self
    {
        return new self($request, $query, $title);
    }

    // ---------------------------------------------------------------- filters

    /** Date or datetime column (or closure($query, $fromDate, $toDate)) the period filter applies to. */
    public function period(string|Closure $column, string $label = 'Period'): self
    {
        $this->periodLabel = $label;
        $this->periodFilter = $column instanceof Closure ? $column
            : fn (Builder $q, string $from, string $to) => $q->whereDate($column, '>=', $from)->whereDate($column, '<=', $to);

        return $this;
    }

    /** Project column (or closure($query, $projectId)). */
    public function project(string|Closure $column = 'project_id'): self
    {
        $this->projectFilter = $column instanceof Closure ? $column : fn (Builder $q, int $id) => $q->where($column, $id);

        return $this;
    }

    /** Store column (or closure($query, $storeId)). */
    public function store(string|Closure $column = 'store_id', string $label = 'Store'): self
    {
        $this->storeLabel = $label;
        $this->storeFilter = $column instanceof Closure ? $column : fn (Builder $q, int $id) => $q->where($column, $id);

        return $this;
    }

    /** Columns searched with LIKE (or closure($query, $term)). */
    public function search(array|Closure $columns, ?string $placeholder = null): self
    {
        $this->searchPlaceholder = $placeholder;
        $this->searchFilter = $columns instanceof Closure ? $columns
            : fn (Builder $q, string $term) => $q->where(function (Builder $w) use ($columns, $term) {
                foreach ($columns as $c) {
                    $w->orWhere($c, 'like', '%'.addcslashes($term, '%_\\').'%');
                }
            });

        return $this;
    }

    /**
     * Export columns: header => fn ($row) => value, or [fn, 'money'|'qty'|'number'].
     * Values stay raw (numbers unformatted) in CSV; PDF formats money and quantities.
     */
    public function columns(array $columns): self
    {
        $this->columns = $columns;

        return $this;
    }

    /** Headers of money columns to total at the bottom of the export. */
    public function totals(array $headers): self
    {
        $this->totals = $headers;

        return $this;
    }

    /**
     * Row-level filter applied after the query, for lists decided by permission checks that SQL cannot express
     * (e.g. "requests waiting for MY decision"). Applied to both the page and the export. Use only on small lists.
     */
    public function afterQuery(Closure $filter): self
    {
        $this->afterQuery = $filter;

        return $this;
    }

    public function perPage(int $n): self
    {
        $this->perPage = $n;

        return $this;
    }

    public function has(string $filter): bool
    {
        return match ($filter) {
            'period' => $this->periodFilter !== null,
            'project' => $this->projectFilter !== null,
            'store' => $this->storeFilter !== null,
            'search' => $this->searchFilter !== null,
            'export' => $this->columns !== [],
            default => false,
        };
    }

    // ---------------------------------------------------------------- values

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    public function range(): array
    {
        return self::rangeFor($this->request);
    }

    /** Period preset (?period=this_month ...) or custom ?from / ?to as [from, to]; [null, null] for all time. */
    public static function rangeFor(Request $request): array
    {
        $now = CarbonImmutable::now();
        $period = self::periodKeyFor($request);
        $date = function (string $key) use ($request): ?CarbonImmutable {
            try {
                return $request->filled($key) ? CarbonImmutable::parse((string) $request->query($key)) : null;
            } catch (\Throwable) {
                return null;
            }
        };

        return match ($period) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            'this_week' => [$now->startOfWeek(), $now->endOfWeek()],
            'last_week' => [$now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek()],
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'last_30' => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            'this_quarter' => [$now->startOfQuarter(), $now->endOfQuarter()],
            'this_year' => [$now->startOfYear(), $now->endOfYear()],
            'last_year' => [$now->subYear()->startOfYear(), $now->subYear()->endOfYear()],
            'custom' => [$date('from'), $date('to')],
            default => [null, null],
        };
    }

    public function periodKey(): string
    {
        return self::periodKeyFor($this->request);
    }

    public static function periodKeyFor(Request $request): string
    {
        $p = (string) $request->query('period', '');
        if ($p === '' && ($request->filled('from') || $request->filled('to'))) {
            return 'custom';
        }

        return array_key_exists($p, self::PERIODS) ? $p : '';
    }

    public function projectId(): ?int
    {
        return $this->request->integer('project_id') ?: null;
    }

    public function storeId(): ?int
    {
        return $this->request->integer('store_id') ?: null;
    }

    public function term(): string
    {
        return trim((string) $this->request->query('q', ''));
    }

    /** Projects offered in the filter: only those the user may see. */
    public function projectOptions(): Collection
    {
        return $this->has('project') ? Project::visibleTo($this->request->user())->orderBy('code')->get(['id', 'code', 'name']) : collect();
    }

    public function storeOptions(): Collection
    {
        return $this->has('store') ? Store::orderBy('id')->get(['id', 'code', 'name']) : collect();
    }

    public function isFiltered(): bool
    {
        return $this->periodKey() !== '' || $this->projectId() || $this->storeId() || $this->term() !== ''
            || collect($this->request->except(['page', 'format']))->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
    }

    /** One line describing the active filters, printed on PDFs. */
    public function describe(): string
    {
        $parts = [];
        [$from, $to] = $this->range();
        if ($from || $to) {
            $parts[] = 'Period: '.($from?->format('d M Y') ?? 'start').' - '.($to?->format('d M Y') ?? 'today');
        }
        if ($id = $this->projectId()) {
            $parts[] = 'Project: '.(Project::whereKey($id)->value('code') ?? $id);
        }
        if ($id = $this->storeId()) {
            $parts[] = $this->storeLabel.': '.(Store::whereKey($id)->value('code') ?? $id);
        }
        if ($this->term() !== '') {
            $parts[] = 'Search: "'.$this->term().'"';
        }
        foreach ($this->request->except(['page', 'format', 'period', 'from', 'to', 'project_id', 'store_id', 'q']) as $k => $v) {
            if (is_scalar($v) && $v !== '') {
                $parts[] = ucfirst(str_replace(['_id', '_'], ['', ' '], $k)).': '.str_replace('_', ' ', (string) $v);
            }
        }

        return $parts ? implode(' · ', $parts) : 'All records';
    }

    public function exportUrl(string $format): string
    {
        return $this->request->fullUrlWithQuery(['format' => $format, 'page' => null]);
    }

    // ---------------------------------------------------------------- results

    public function query(): Builder
    {
        if (! $this->applied) {
            $this->applied = true;
            [$from, $to] = $this->range();
            if ($this->periodFilter && ($from || $to)) {
                ($this->periodFilter)($this->query, ($from ?? CarbonImmutable::create(2000))->toDateString(), ($to ?? CarbonImmutable::now())->toDateString());
            }
            if ($this->projectFilter && $this->projectId()) {
                ($this->projectFilter)($this->query, $this->projectId());
            }
            if ($this->storeFilter && $this->storeId()) {
                ($this->storeFilter)($this->query, $this->storeId());
            }
            if ($this->searchFilter && $this->term() !== '') {
                ($this->searchFilter)($this->query, $this->term());
            }
        }

        return $this->query;
    }

    public function paginate(): LengthAwarePaginator
    {
        if (! $this->afterQuery) {
            return $this->query()->paginate($this->perPage)->withQueryString();
        }
        $all = ($this->afterQuery)($this->query()->get())->values();
        $page = \Illuminate\Pagination\Paginator::resolveCurrentPage();

        return (new \Illuminate\Pagination\LengthAwarePaginator($all->forPage($page, $this->perPage)->values(), $all->count(), $this->perPage, $page,
            ['path' => $this->request->url()]))->withQueryString();
    }

    /** Export when ?format=csv|pdf, otherwise render the page with the paginated rows. */
    public function respond(Closure $view): mixed
    {
        $format = $this->request->query('format');
        if ($this->columns && in_array($format, ['csv', 'pdf'], true)) {
            return $this->export($format);
        }

        return $view($this->paginate(), $this);
    }

    private function export(string $format): Response
    {
        $headers = array_keys($this->columns);
        $types = array_values(array_map(fn ($c) => is_array($c) ? ($c[1] ?? null) : null, $this->columns));
        $getters = array_values(array_map(fn ($c) => is_array($c) ? $c[0] : $c, $this->columns));
        $records = $this->query()->limit(self::EXPORT_LIMIT)->get();
        if ($this->afterQuery) {
            $records = ($this->afterQuery)($records);
        }
        $rows = $records
            ->map(fn ($r) => array_map(fn ($g) => $g($r), $getters))->values();

        $numeric = array_keys(array_filter($types, fn ($t) => in_array($t, ['money', 'qty', 'number'], true)));
        $money = array_keys(array_filter($types, fn ($t) => $t === 'money'));
        $sum = [];
        foreach ($this->totals as $h) {
            $i = array_search($h, $headers, true);
            if ($i !== false) {
                $sum[$i] = $rows->reduce(fn ($c, $r) => bcadd($c, (string) ((float) ($r[$i] ?? 0)), 2), '0');
            }
        }
        $file = str($this->title)->slug().'-'.now()->format('Ymd-His');
        $note = $rows->count() >= self::EXPORT_LIMIT ? 'Only the first '.self::EXPORT_LIMIT.' rows are included. Narrow the filters to export the rest.' : null;

        if ($format === 'csv') {
            return self::csv($file.'.csv', $headers, $rows, $sum);
        }

        return Pdf::download('pdf.report', [
            'title' => $this->title, 'headers' => $headers, 'rows' => $rows, 'numeric' => $numeric, 'money' => $money,
            'sum' => $sum, 'note' => $note, 'filterText' => $this->describe(),
        ], $file.'.pdf', count($headers) > 6 ? 'landscape' : 'portrait');
    }

    /** CSV with a UTF-8 BOM (Excel) and formula-injection protection. */
    public static function csv(string $filename, array $headers, iterable $rows, array $sum = []): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $sum) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $r) {
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
