@extends('layouts.app')
@section('title', 'Stock & items')
@section('content')
<div class="page-head"><div><h1>Stock &amp; items</h1><p>Single central store. Rows highlighted are at or below minimum level.</p></div>
    <div class="actions">
        @can('manage-catalog')<a class="btn" href="{{ route('items.create') }}">+ New item</a>@endcan
        @can('manage-stock')<a class="btn secondary" href="{{ route('receipts.create', ['type' => 'opening']) }}">Opening balances</a><a class="btn secondary" href="{{ route('adjustments.create') }}">Adjustment</a>@endcan
    </div></div>
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
            <td>@if($i->isLowStock())<span class="badge pending">Low</span>@endif</td></tr>
    @empty <tr><td colspan="8" class="empty">No items.</td></tr> @endforelse</tbody>
</table></div>
@endsection
