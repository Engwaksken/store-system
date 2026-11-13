<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreRequest;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StoreController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Store::class);
        $stores = Store::with('project', 'users')->withCount(['balances as stocked_items' => fn ($q) => $q->where('on_hand', '>', 0)])
            ->orderBy('id')->paginate(25);

        return view('stores.index', ['stores' => $stores, 'mine' => Store::operableBy($request->user())->pluck('id')]);
    }

    public function create()
    {
        Gate::authorize('create', Store::class);

        return view('stores.form', ['store' => new Store(['is_active' => true, 'type' => 'central']), ...$this->options()]);
    }

    public function store(StoreRequest $request)
    {
        $store = Store::create($request->storeData());
        $user = $request->user();
        if ($user->isAdmin()) {
            $store->users()->sync($request->validated('user_ids', []));
        } else {
            $store->users()->attach($user->id); // a storekeeper who opens a store operates it
        }
        ActivityLog::record('store.created', "Store {$store->code} created", $store);

        return redirect()->route('stores.show', $store)->with('status', "Store {$store->code} created.");
    }

    public function show(Request $request, Store $store)
    {
        Gate::authorize('view', $store);
        $balances = $store->balances()->with('item.unit')->where('on_hand', '!=', 0)
            ->get()->sortBy(fn ($b) => $b->item->name)->values();

        return view('stores.show', [
            'store' => $store->load('project', 'users'),
            'balances' => $balances,
            'value' => $balances->reduce(fn ($c, $b) => bcadd($c, bcmul((string) $b->on_hand, (string) $b->item->avg_cost, 2), 2), '0'),
            'canOperate' => $store->isOperableBy($request->user()),
        ]);
    }

    public function edit(Store $store)
    {
        Gate::authorize('update', $store);

        return view('stores.form', ['store' => $store->load('users'), ...$this->options()]);
    }

    public function update(StoreRequest $request, Store $store)
    {
        $data = $request->storeData();
        if ($store->id === Store::DEFAULT_ID) {
            $data['is_active'] = true; // the main store holds legacy history and is the default for older documents
            $data['type'] = 'central';
            $data['project_id'] = null;
        }
        $store->update($data);
        if ($request->user()->can('assignUsers', $store) && $request->has('user_ids')) {
            $store->users()->sync($request->validated('user_ids', []));
        }
        ActivityLog::record('store.updated', "Store {$store->code} updated", $store);

        return redirect()->route('stores.show', $store)->with('status', 'Store updated.');
    }

    private function options(): array
    {
        return [
            'projects' => Project::whereIn('status', ['planned', 'active', 'on_hold'])->orderBy('code')->get(),
            'storekeepers' => User::where('role', Role::Storekeeper)->where('is_active', true)->orderBy('name')->get(),
        ];
    }
}
