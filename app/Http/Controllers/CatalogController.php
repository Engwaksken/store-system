<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryUnit;
use App\Support\ListPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('view-stock');
        // "Low stock" = same rule as InventoryItem::isLowStock(): a minimum is set and the total across all stores is at or below it.
        $query = InventoryItem::with('category', 'unit', 'balances')
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->boolean('low'), fn ($q) => $q->where('min_level', '>', 0)
                ->whereRaw('(select coalesce(sum(b.on_hand), 0) from inventory_balances b where b.item_id = inventory_items.id) <= inventory_items.min_level'))
            ->orderBy('name');

        $all = InventoryItem::with('balances')->get();
        $stats = [
            'items' => $all->count(),
            'active' => $all->where('is_active', true)->count(),
            'low' => $all->filter->isLowStock()->count(),
            'categories' => InventoryCategory::count(),
        ];

        $storeId = $request->integer('store_id') ?: null;
        $columns = [
            'SKU' => fn ($i) => $i->sku, 'Item' => fn ($i) => $i->name, 'Category' => fn ($i) => $i->category->name,
            'On hand' => [fn ($i) => $i->onHand($storeId), 'qty'], 'Unit' => fn ($i) => $i->unit->abbreviation,
            'Min level' => [fn ($i) => $i->min_level, 'qty'],
        ];
        if (Gate::allows('view-stock-value')) {
            $columns['Avg cost'] = [fn ($i) => $i->avg_cost, 'money'];
            $columns['Stock value'] = [fn ($i) => bcmul($i->onHand($storeId), (string) $i->avg_cost, 2), 'money'];
        }
        $columns += ['Active' => fn ($i) => $i->is_active ? 'Yes' : 'No', 'Low stock' => fn ($i) => $i->isLowStock() ? 'Yes' : 'No'];
        $categories = InventoryCategory::orderBy('name')->get();

        return ListPage::make($request, $query, 'Stock and items')
            ->store(fn ($q, $id) => $q->whereHas('balances', fn ($b) => $b->where('store_id', $id)))
            ->search(['sku', 'name'], 'SKU or name')
            ->columns($columns)->totals(isset($columns['Stock value']) ? ['Stock value'] : [])
            ->respond(fn ($items, $list) => view('items.index', ['items' => $items, 'list' => $list, 'storeId' => $storeId, 'categories' => $categories, 'stats' => $stats]));
    }

    public function show(InventoryItem $item)
    {
        Gate::authorize('view-stock');
        $movements = Gate::allows('view-stock-ledger')
            ? $item->movements()->with('project', 'actor', 'store')->latest('id')->paginate(50)
            : null;

        return view('items.show', ['item' => $item->load('category', 'unit', 'balances.store'), 'movements' => $movements]);
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

    public function importTemplate()
    {
        Gate::authorize('manage-catalog');

        return \App\Support\CsvImport::template('items-template.csv', ['sku', 'name', 'category', 'unit', 'min_level'],
            [['CEM-425', 'Cement 42.5R (50kg)', InventoryCategory::orderBy('name')->value('name') ?? 'Cement & aggregates', InventoryUnit::orderBy('name')->value('abbreviation') ?? 'bag', '20']]);
    }

    /** All-or-nothing import of new catalogue items. Category by name, unit by abbreviation (both must exist). */
    public function import(Request $request)
    {
        Gate::authorize('manage-catalog');
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:2048']);
        [$rows, $error] = \App\Support\CsvImport::read($request->file('file'), ['sku', 'name', 'category', 'unit', 'min_level']);
        if ($error) {
            return back()->withErrors(['file' => $error]);
        }
        $cats = InventoryCategory::pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id]);
        $units = InventoryUnit::pluck('id', 'abbreviation')->mapWithKeys(fn ($id, $a) => [mb_strtolower($a) => $id]);
        $existing = InventoryItem::pluck('sku')->map(fn ($s) => mb_strtolower($s))->flip();
        $seen = $errors = $new = [];
        foreach ($rows as $line => $r) {
            $sku = $r['sku'];
            $key = mb_strtolower($sku);
            $min = \App\Support\CsvImport::number($r['min_level'] === '' ? '0' : $r['min_level']);
            if ($sku === '' || $r['name'] === '') {
                $errors[] = "Row {$line}: sku and name are required.";
            } elseif (mb_strlen($sku) > 40) {
                $errors[] = "Row {$line}: SKU is longer than 40 characters.";
            } elseif (isset($existing[$key]) || isset($seen[$key])) {
                $errors[] = "Row {$line}: SKU {$sku} already exists.";
            } elseif (! isset($cats[mb_strtolower($r['category'])])) {
                $errors[] = "Row {$line}: unknown category '{$r['category']}'.";
            } elseif (! isset($units[mb_strtolower($r['unit'])])) {
                $errors[] = "Row {$line}: unknown unit '{$r['unit']}' (use the abbreviation, e.g. bag).";
            } elseif ($min === null) {
                $errors[] = "Row {$line}: min_level must be a number of 0 or more.";
            } else {
                $seen[$key] = true;
                $new[] = ['sku' => $sku, 'name' => mb_substr($r['name'], 0, 255), 'category_id' => $cats[mb_strtolower($r['category'])],
                    'unit_id' => $units[mb_strtolower($r['unit'])], 'min_level' => $min, 'is_active' => true];
            }
        }
        if ($errors) {
            return back()->withErrors(array_slice($errors, 0, 20));
        }
        \Illuminate\Support\Facades\DB::transaction(fn () => collect($new)->each(fn ($d) => InventoryItem::create($d)));
        ActivityLog::record('catalog.imported', count($new).' items imported from '.$request->file('file')->getClientOriginalName());

        return redirect()->route('items.index')->with('status', count($new).' items imported. Record opening balances next.');
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
