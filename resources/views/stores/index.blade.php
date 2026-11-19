@extends('layouts.app')
@section('title', 'Stores')
@section('content')
<div class="page-head"><div><h1>Stores</h1><p>Central store and project site stores. Storekeepers can only move stock in stores they are assigned to.</p></div>
    <div class="actions">
        @can('create', App\Models\Store::class)<a class="btn" data-modal href="{{ route('stores.create') }}"><i class="fas fa-plus"></i> New store</a>@endcan
        @can('create', App\Models\StockTransfer::class)<a class="btn secondary" data-modal href="{{ route('transfers.create') }}">New transfer</a>@endcan
    </div></div>
<x-stats :cards="[
    ['Stores', $stats['total'], 'fa-warehouse', 'navy'],
    ['Active', $stats['active'], 'fa-circle-check'],
    ['Site stores', $stats['site'], 'fa-person-digging', 'gold'],
    ['Assigned to you', $stats['mine'], 'fa-user-gear'],
]" />
<x-list-filters :list="$list" />
@php($bulk = auth()->user()->can('delete-stores'))
<div data-bulk-scope="stores">
@if($bulk)<x-bulk-bar :action="route('stores.bulk')" scope="stores"><button class="btn small danger" name="action" value="delete" data-confirm="Delete the selected stores? The main store and stores that ever held stock are skipped and listed (deactivate those instead)." data-confirm-ok="Yes, delete"><i class="fas fa-trash-can"></i> Delete</button></x-bulk-bar>@endif
<div class="card table-wrap"><table>
    <thead><tr>@if($bulk)<th class="check-col"><input type="checkbox" data-check-all aria-label="Select all on this page"></th>@endif<th>Code</th><th>Name</th><th>Type</th><th>Project</th><th>Location</th><th>Storekeepers</th><th class="num">Items in stock</th><th>Status</th><th></th></tr></thead>
    <tbody>@forelse($stores as $s)
        <tr>@if($bulk)<td class="check-col"><input type="checkbox" value="{{ $s->id }}" data-row-check aria-label="Select {{ $s->code }}"></td>@endif<td><a href="{{ route('stores.show', $s) }}">{{ $s->code }}</a> @if($mine->contains($s->id))<span class="badge approved">yours</span>@endif</td><td>{{ $s->name }}</td>
            <td>{{ \App\Models\Store::TYPES[$s->type] ?? $s->type }}</td><td>{{ $s->project?->code }}</td><td>{{ $s->location }}</td>
            <td>{{ $s->users->pluck('name')->implode(', ') ?: '-' }}</td><td class="num">{{ $s->stocked_items }}</td>
            <td>@if($s->is_active)<span class="badge approved">Active</span>@else<span class="badge muted">Inactive</span>@endif</td>
            <td class="right">@can('update', $s)<a class="btn-icon" data-modal href="{{ route('stores.edit', $s) }}" title="Edit {{ $s->code }}" aria-label="Edit {{ $s->code }}"><i class="fas fa-pen"></i></a>@endcan</td></tr>
    @empty <tr><td colspan="{{ 9 + ($bulk ? 1 : 0) }}">No stores{{ $list->isFiltered() ? ' match these filters' : '' }}.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $stores->links() }}</div>
</div>
@endsection
