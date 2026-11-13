@extends('layouts.app')
@section('title', 'Stores')
@section('content')
<div class="page-head"><div><h1>Stores</h1><p>Central store and project site stores. Storekeepers can only move stock in stores they are assigned to.</p></div>
    <div class="actions">
        @can('create', App\Models\Store::class)<a class="btn" href="{{ route('stores.create') }}">+ New store</a>@endcan
        @can('create', App\Models\StockTransfer::class)<a class="btn secondary" href="{{ route('transfers.create') }}">New transfer</a>@endcan
    </div></div>
<div class="card table-wrap"><table>
    <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Project</th><th>Location</th><th>Storekeepers</th><th class="num">Items in stock</th><th>Status</th></tr></thead>
    <tbody>@forelse($stores as $s)
        <tr><td><a href="{{ route('stores.show', $s) }}">{{ $s->code }}</a> @if($mine->contains($s->id))<span class="badge approved">yours</span>@endif</td><td>{{ $s->name }}</td>
            <td>{{ \App\Models\Store::TYPES[$s->type] ?? $s->type }}</td><td>{{ $s->project?->code }}</td><td>{{ $s->location }}</td>
            <td>{{ $s->users->pluck('name')->implode(', ') ?: '—' }}</td><td class="num">{{ $s->stocked_items }}</td>
            <td>@if($s->is_active)<span class="badge approved">Active</span>@else<span class="badge muted">Inactive</span>@endif</td></tr>
    @empty <tr><td colspan="8" class="empty">No stores.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $stores->links() }}</div>
@endsection
