@extends('layouts.app')
@section('title', 'Expense claims')
@section('content')
<div class="page-head"><div><h1>Expense claims</h1><p>Site costs paid by staff. The project manager approves; the accountant pays, which records the expense once.</p></div>
    @can('submit-claim')<div class="actions"><a class="btn" href="{{ route('claims.create') }}">+ New claim</a></div>@endcan</div>
<form method="GET" class="card filters"><div class="grid grid-4">
    <div><label for="status">Status</label><select id="status" name="status"><option value="">All</option>@foreach(\App\Models\ExpenseClaim::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select></div>
    <div><label>&nbsp;</label><button class="btn small">Filter</button></div>
</div></form>
<div class="card table-wrap"><table>
    <thead><tr><th>Claim</th><th>Date</th><th>Project</th><th>Claimant</th><th>Category</th><th class="num">Amount (UGX)</th><th>Status</th></tr></thead>
    <tbody>@forelse($claims as $c)
        <tr><td><a href="{{ route('claims.show', $c) }}">{{ $c->claim_no }}</a></td><td class="nowrap">{{ $c->incurred_on->format('d M Y') }}</td><td>{{ $c->project->code }}</td><td>{{ $c->claimant->name }}</td><td>{{ $c->category->name }}</td>
            <td class="num">{{ ugx($c->amount) }}</td><td><span class="badge {{ $c->tone() }}">{{ $c->statusLabel() }}</span></td></tr>
    @empty <tr><td colspan="7" class="empty">No claims.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $claims->links() }}</div>
@endsection
