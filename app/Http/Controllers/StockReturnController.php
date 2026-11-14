<?php

namespace App\Http\Controllers;

use App\Models\MaterialIssue;
use App\Models\StockReturn;
use App\Models\Store;
use App\Services\StockReturnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Returns of unused material from site back to a store, against the original issue voucher. */
class StockReturnController extends Controller
{
    public function index()
    {
        Gate::authorize('view-returns');

        return view('returns.index', [
            'returns' => StockReturn::with('issue', 'project', 'store', 'receiver')->latest('id')->paginate(25),
            'stats' => [
                'total' => StockReturn::count(),
                'month' => StockReturn::where('returned_at', '>=', now()->startOfMonth())->count(),
                'value' => StockReturn::sum('total_value'),
            ],
        ]);
    }

    public function create(Request $request, MaterialIssue $issue)
    {
        Gate::authorize('record-return', $issue);

        return view('returns.create', [
            'issue' => $issue->load('project', 'store', 'lines.item.unit', 'lines.returnLines'),
            'stores' => Store::operableBy($request->user())->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request, MaterialIssue $issue, StockReturnService $service)
    {
        Gate::authorize('record-return', $issue);
        $data = $request->validate([
            'qty' => 'required|array',
            'qty.*' => 'nullable|numeric|min:0',
            'store_id' => 'required|integer|exists:stores,id',
            'returned_by_name' => 'required|string|max:255',
            'reason' => 'required|string|min:5|max:1000',
            'idempotency_key' => 'nullable|string|max:64',
        ]);
        $return = $service->record($request->user(), $issue, $data['qty'], $data['returned_by_name'], $data['reason'],
            (int) $data['store_id'], $data['idempotency_key'] ?? null);

        return redirect()->route('returns.show', $return)->with('status', "Return {$return->return_no} recorded; {$issue->project->code} credited ".ugx($return->total_value).' '.currency().'.');
    }

    public function show(StockReturn $return)
    {
        Gate::authorize('view-returns');

        return view('returns.show', ['return' => $return->load('issue', 'project', 'store', 'receiver', 'lines.item.unit')]);
    }
}
