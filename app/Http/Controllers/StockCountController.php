<?php

namespace App\Http\Controllers;

use App\Models\StockCount;
use App\Models\Store;
use App\Services\StockCountService;
use App\Support\ListPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StockCountController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('view-counts');

        $byStatus = StockCount::query()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $stats = ['total' => (int) $byStatus->sum(), 'draft' => (int) ($byStatus['draft'] ?? 0), 'submitted' => (int) ($byStatus['submitted'] ?? 0), 'approved' => (int) ($byStatus['approved'] ?? 0)];

        $query = StockCount::with('store', 'counter', 'decider')->withCount('lines')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('id');

        return ListPage::make($request, $query, 'Stock counts')
            ->period('created_at', 'Created')->store()->search(['count_no', 'notes'], 'Count number or notes')
            ->columns([
                'Count' => fn ($c) => $c->count_no, 'Store' => fn ($c) => $c->store->code, 'Counted by' => fn ($c) => $c->counter->name,
                'Created' => fn ($c) => $c->created_at->format('Y-m-d'), 'Submitted' => fn ($c) => $c->submitted_at?->format('Y-m-d'),
                'Lines' => [fn ($c) => $c->lines_count, 'number'], 'Status' => fn ($c) => $c->statusLabel(),
                'Decided by' => fn ($c) => $c->decider?->name, 'Decided' => fn ($c) => $c->decided_at?->format('Y-m-d'),
            ])
            ->respond(fn ($counts, $list) => view('counts.index', ['counts' => $counts, 'list' => $list, 'stats' => $stats]));
    }

    public function create(Request $request)
    {
        Gate::authorize('start-count');

        return view('counts.create', ['stores' => Store::operableBy($request->user())->orderBy('id')->get()]);
    }

    public function store(Request $request, StockCountService $service)
    {
        Gate::authorize('start-count');
        $data = $request->validate(['store_id' => 'required|integer|exists:stores,id', 'notes' => 'nullable|string|max:1000']);
        $count = $service->create($request->user(), (int) $data['store_id'], null, $data['notes'] ?? null);

        return redirect()->route('counts.show', $count)->with('status', "Count sheet {$count->count_no} created. Book quantities are frozen as of now.");
    }

    public function show(StockCount $count)
    {
        Gate::authorize('view-counts');

        return view('counts.show', ['count' => $count->load('store', 'counter', 'decider', 'lines.item.unit', 'adjustments')]);
    }

    public function update(Request $request, StockCount $count, StockCountService $service)
    {
        Gate::authorize('edit-count', $count);
        $data = $request->validate([
            'lines' => 'required|array',
            'lines.*.counted_qty' => 'nullable|numeric|min:0',
            'lines.*.reason' => 'nullable|string|max:500',
        ]);
        $service->record($request->user(), $count, $data['lines']);
        if ($request->input('action') === 'submit') {
            $service->submit($request->user(), $count);

            return redirect()->route('counts.show', $count)->with('status', 'Count submitted for approval.');
        }

        return redirect()->route('counts.show', $count)->with('status', 'Counted quantities saved.');
    }

    public function decide(Request $request, StockCount $count, StockCountService $service)
    {
        Gate::authorize('decide-count', $count);
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'reason' => 'nullable|required_if:decision,rejected|string|max:1000']);
        $service->decide($request->user(), $count, $data['decision'], $data['reason'] ?? null);

        return redirect()->route('counts.show', $count)->with('status', $data['decision'] === 'approved'
            ? 'Count approved; variances posted to the stock ledger.' : 'Count rejected; nothing was posted.');
    }
}
