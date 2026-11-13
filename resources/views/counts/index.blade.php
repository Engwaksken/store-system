@extends('layouts.app')
@section('title', 'Stock counts')
@section('content')
<div class="page-head"><div><h1>Stock counts</h1><p>Physical counts per store. Variances post to the ledger only after approval by someone other than the counter.</p></div>
    @can('start-count')<div class="actions"><a class="btn" href="{{ route('counts.create') }}">+ New count</a></div>@endcan</div>
<div class="card table-wrap"><table>
    <thead><tr><th>Count</th><th>Store</th><th>Counted by</th><th>Created</th><th class="num">Lines</th><th>Status</th><th>Decided by</th></tr></thead>
    <tbody>@forelse($counts as $c)
        <tr><td><a href="{{ route('counts.show', $c) }}">{{ $c->count_no }}</a></td><td>{{ $c->store->code }}</td><td>{{ $c->counter->name }}</td><td class="nowrap">{{ $c->created_at->format('d M Y') }}</td>
            <td class="num">{{ $c->lines_count }}</td><td><span class="badge {{ $c->tone() }}">{{ $c->statusLabel() }}</span></td><td>{{ $c->decider?->name }}</td></tr>
    @empty <tr><td colspan="7" class="empty">No counts yet.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $counts->links() }}</div>
@endsection
