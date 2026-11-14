@extends('layouts.app')
@section('title', 'Stock & items')
@section('content')
<div class="page-head"><div><h1>Stock &amp; items</h1><p>On hand is the total across all stores; open an item for the per-store split. Highlighted rows are at or below minimum level.</p></div>
    <div class="actions">
        @can('manage-catalog')<a class="btn" data-modal href="{{ route('items.create') }}"><i class="fas fa-plus"></i> New item</a>@endcan
        @can('manage-stock')<a class="btn secondary" data-modal href="{{ route('receipts.create', ['type' => 'opening']) }}"><i class="fas fa-door-open"></i> Opening balances</a><a class="btn secondary" data-modal href="{{ route('adjustments.create') }}">Adjustment</a>@endcan
    </div></div>
<x-stats :cards="[
    ['Items', number_format($stats['items']), 'fa-boxes-stacked', 'navy'],
    ['Active', number_format($stats['active']), 'fa-circle-check'],
    ['Low stock', number_format($stats['low']), 'fa-triangle-exclamation', 'bad', route('items.index', ['low' => 1])],
    ['Categories', $stats['categories'], 'fa-tags', 'gold'],
]" />
@can('manage-catalog')
<details class="card"><summary><strong>Import items from a spreadsheet (CSV)</strong></summary>
    <form method="POST" action="{{ route('items.import') }}" enctype="multipart/form-data" style="margin-top:.75rem">@csrf
        <p class="muted">1. <a href="{{ route('items.import-template') }}">Download the template</a>. 2. One row per new item: sku, name, category (exact name), unit (abbreviation), min_level. 3. Save as CSV and upload. If any row is wrong nothing is imported and the errors are listed.</p>
        <div class="grid grid-4"><div style="grid-column:span 2"><label for="items_file">CSV file</label><input id="items_file" type="file" name="file" accept=".csv,text/csv" required></div>
            <div><label>&nbsp;</label><button class="btn secondary">Import items</button></div></div>
    </form></details>
@endcan
<form method="GET" class="card filters"><div class="grid grid-4">
    <div><label>Search</label><input type="text" name="q" value="{{ request('q') }}" placeholder="SKU or name"></div>
    <div><label>Category</label><select name="category_id"><option value="">All</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected(request('category_id') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
    <div><label>&nbsp;</label><label class="check"><input type="checkbox" name="low" value="1" @checked(request('low'))> Low stock only</label></div>
    <div><label>&nbsp;</label><button class="btn small">Filter</button></div>
</div></form>
<div class="card table-wrap"><table>
    <thead><tr><th>SKU</th><th>Item</th><th>Category</th><th class="num">On hand</th><th>Unit</th><th class="num">Min level</th>@can('view-stock-value')<th class="num">Avg cost</th>@endcan<th></th></tr></thead>
    <tbody>@forelse($items as $i)
        <tr @class(['low' => $i->isLowStock()])><td>{{ $i->sku }}</td><td><a href="{{ route('items.show', $i) }}">{{ $i->name }}</a> @unless($i->is_active)<span class="badge muted">inactive</span>@endunless</td><td>{{ $i->category->name }}</td>
            <td class="num"><strong>{{ qty($i->onHand()) }}</strong></td><td>{{ $i->unit->abbreviation }}</td><td class="num">{{ qty($i->min_level) }}</td>
            @can('view-stock-value')<td class="num">{{ ugx($i->avg_cost) }}</td>@endcan
            <td><div class="row-actions">@if($i->isLowStock())<span class="badge pending">Low</span>@endif
                @can('manage-catalog')<a class="btn-icon" data-modal href="{{ route('items.edit', $i) }}" title="Edit {{ $i->sku }}" aria-label="Edit {{ $i->sku }}"><i class="fas fa-pen"></i></a>@endcan</div></td></tr>
    @empty <tr><td colspan="8" class="empty">No items.</td></tr> @endforelse</tbody>
</table></div>
@endsection
