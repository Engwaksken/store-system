@extends('layouts.app')
@section('title', 'Expense claims')
@section('content')
<div class="page-head"><div><h1>Expense claims</h1><p>Site costs paid by staff. The project manager approves; the accountant pays, which records the expense once.</p></div>
    @can('submit-claim')<div class="actions"><a class="btn" data-modal href="{{ route('claims.create') }}"><i class="fas fa-plus"></i> New claim</a></div>@endcan</div>
<x-stats :cards="[
    ['Awaiting approval', $stats['submitted'], 'fa-hourglass-half', 'gold', route('claims.index', ['status' => 'submitted'])],
    ['Approved, unpaid', $stats['approved'], 'fa-file-circle-check', 'navy', route('claims.index', ['status' => 'approved'])],
    ['Unpaid value ('.currency().')', ugx($stats['unpaid_value']), 'fa-money-bill-wave', 'bad'],
    ['Paid', $stats['paid'], 'fa-circle-check', '', route('claims.index', ['status' => 'paid'])],
]" />
<x-list-filters :list="$list">
    <div><label for="f_status">Status</label><select id="f_status" name="status"><option value="">All statuses</option>@foreach(\App\Models\ExpenseClaim::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select></div>
</x-list-filters>
<div class="card table-wrap"><table>
    <thead><tr><th>Claim</th><th>Date</th><th>Project</th><th>Claimant</th><th>Category</th><th class="num">Amount ({{ currency() }})</th><th>Status</th></tr></thead>
    <tbody>@forelse($claims as $c)
        <tr><td><a href="{{ route('claims.show', $c) }}">{{ $c->claim_no }}</a></td><td class="nowrap">{{ $c->incurred_on->format('d M Y') }}</td><td>{{ $c->project->code }}</td><td>{{ $c->claimant->name }}</td><td>{{ $c->category->name }}</td>
            <td class="num">{{ ugx($c->amount) }}</td><td><span class="badge {{ $c->tone() }}">{{ $c->statusLabel() }}</span></td></tr>
    @empty <tr><td colspan="7" class="empty">No claims{{ $list->isFiltered() ? ' match these filters' : '' }}.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $claims->links() }}</div>
@endsection
