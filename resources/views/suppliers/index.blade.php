@extends('layouts.app')
@section('title', 'Suppliers')
@section('content')
<div class="page-head"><div><h1>Suppliers</h1></div>
    @can('manage-suppliers')<div class="actions"><a class="btn" data-modal href="{{ route('suppliers.create') }}"><i class="fas fa-plus"></i> New supplier</a></div>@endcan</div>
<form method="GET" class="card filters"><div class="grid grid-4">
    <div><label for="q">Search</label><input id="q" type="text" name="q" value="{{ request('q') }}" placeholder="Code or name"></div>
    <div><label>&nbsp;</label><button class="btn small">Search</button></div>
</div></form>
<div class="card table-wrap"><table>
    <thead><tr><th>Code</th><th>Name</th><th>Contact</th><th>Phone</th><th>Terms</th><th class="num">POs</th><th>Status</th>@can('manage-suppliers')<th></th>@endcan</tr></thead>
    <tbody>@forelse($suppliers as $s)
        <tr><td><a href="{{ route('suppliers.show', $s) }}">{{ $s->code }}</a></td><td>{{ $s->name }}</td><td>{{ $s->contact_person }}</td><td>{{ $s->phone }}</td><td>{{ $s->payment_terms }}</td>
            <td class="num">{{ $s->purchase_orders_count }}</td><td>@if($s->is_active)<span class="badge approved">Active</span>@else<span class="badge muted">Inactive</span>@endif</td>
            @can('manage-suppliers')<td class="right"><a class="btn-icon" data-modal href="{{ route('suppliers.edit', $s) }}" title="Edit {{ $s->name }}" aria-label="Edit {{ $s->name }}"><i class="fas fa-pen"></i></a></td>@endcan</tr>
    @empty <tr><td colspan="8" class="empty">No suppliers yet.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $suppliers->links() }}</div>
@endsection
