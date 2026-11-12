<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('view-stock');
        $items = InventoryItem::with('category', 'unit', 'balance')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$request->q}%")->orWhere('sku', 'like', "%{$request->q}%")))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->orderBy('name')->get();
        if ($request->boolean('low')) {
            $items = $items->filter->isLowStock()->values();
        }

        return view('items.index', ['items' => $items, 'categories' => InventoryCategory::orderBy('name')->get()]);
    }

    public function show(InventoryItem $item)
    {
        Gate::authorize('view-stock');
        $movements = Gate::allows('view-stock-ledger')
            ? $item->movements()->with('project', 'actor')->latest('id')->paginate(50)
            : null;

        return view('items.show', ['item' => $item->load('category', 'unit', 'balance'), 'movements' => $movements]);
    }

    public function setup()
    {
        Gate::authorize('manage-catalog');

        return view('items.setup', ['categories' => InventoryCategory::withCount('items')->orderBy('name')->get(), 'units' => InventoryUnit::orderBy('name')->get()]);
    }

    public function storeCategory(Request $request)
    {
        Gate::authorize('manage-catalog');
        $c = InventoryCategory::create($request->validate(['name' => 'required|string|max:100|unique:inventory_categories,name']));
        ActivityLog::record('catalog.category', "Category {$c->name} added", $c);

        return back()->with('status', 'Category added.');
    }

    public function storeUnit(Request $request)
    {
        Gate::authorize('manage-catalog');
        $u = InventoryUnit::create($request->validate([
            'name' => 'required|string|max:50',
            'abbreviation' => 'required|string|max:15|unique:inventory_units,abbreviation',
        ]));
        ActivityLog::record('catalog.unit', "Unit {$u->abbreviation} added", $u);

        return back()->with('status', 'Unit added.');
    }

    public function create()
    {
        Gate::authorize('manage-catalog');

        return view('items.form', ['item' => new InventoryItem(['is_active' => true]), ...$this->options()]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-catalog');
        $item = InventoryItem::create($this->validated($request));
        ActivityLog::record('catalog.item_created', "Item {$item->sku} created", $item);

        return redirect()->route('items.index')->with('status', "Item {$item->sku} created. Record its opening balance under Stock receipts.");
    }

    public function edit(InventoryItem $item)
    {
        Gate::authorize('manage-catalog');

        return view('items.form', ['item' => $item, ...$this->options()]);
    }

    public function update(Request $request, InventoryItem $item)
    {
        Gate::authorize('manage-catalog');
        $item->update($this->validated($request, $item));
        ActivityLog::record('catalog.item_updated', "Item {$item->sku} updated", $item);

        return redirect()->route('items.show', $item)->with('status', 'Item updated.');
    }

    private function options(): array
    {
        return ['categories' => InventoryCategory::orderBy('name')->get(), 'units' => InventoryUnit::orderBy('name')->get()];
    }

    private function validated(Request $request, ?InventoryItem $item = null): array
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:40', Rule::unique('inventory_items')->ignore($item?->id)],
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:inventory_categories,id',
            'unit_id' => 'required|exists:inventory_units,id',
            'min_level' => 'required|numeric|min:0',
        ]);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
