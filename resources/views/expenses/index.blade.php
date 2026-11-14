@extends('layouts.app')
@section('title', 'Expenses')
@section('content')
<div class="page-head"><div><h1>Project expenses</h1><p>Cash paid directly for projects. Store stock purchases are recorded as stock receipts instead.</p></div>
    @can('record-expense')<a class="btn" data-modal href="{{ route('expenses.create') }}"><i class="fas fa-plus"></i> Record expense</a>@endcan</div>
<x-stats :cards="[
    ['Expenses (filtered)', number_format($stats['count']), 'fa-receipt', 'navy'],
    ['Total (filtered, '.currency().')', ugx($total), 'fa-money-bill-wave'],
    ['This month ('.currency().')', ugx($stats['month']), 'fa-calendar-day', 'gold'],
    ['Without proof', $stats['no_proof'], 'fa-paperclip', $stats['no_proof'] ? 'bad' : ''],
]" />
<form method="GET" class="card filters"><div class="grid grid-4">
    <div><label>Project</label><select name="project_id"><option value="">All</option>@foreach($projects as $p)<option value="{{ $p->id }}" @selected(request('project_id') == $p->id)>{{ $p->code }}</option>@endforeach</select></div>
    <div><label>Category</label><select name="category_id"><option value="">All</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected(request('category_id') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
    <div><label>From</label><input type="date" name="from" value="{{ request('from') }}"></div>
    <div><label>To</label><input type="date" name="to" value="{{ request('to') }}"></div>
</div><div class="actions" style="margin-top:.75rem"><button class="btn small">Filter</button></div></form>
<div class="card table-wrap"><table>
    <thead><tr><th>Date</th><th>Project</th><th>Category</th><th>Description</th><th>Mode</th><th>Ref</th><th class="num">Amount ({{ currency() }})</th><th>Proof</th><th></th></tr></thead>
    <tbody>@forelse($expenses as $e)
        <tr><td class="nowrap">{{ $e->incurred_on->format('d M Y') }}</td><td>{{ $e->project->code }}</td><td>{{ $e->category->name }}</td><td>{{ $e->description }}</td><td>{{ str_replace('_', ' ', $e->payment_mode) }}</td><td>{{ $e->reference }}</td>
            <td class="num">{{ ugx($e->amount) }}</td><td>@if($e->receipt_path)<a href="{{ route('expenses.proof', $e) }}"><i class="fas fa-paperclip"></i> file</a>@endif</td>
            <td>@can('record-expense')<a class="btn-icon" data-modal href="{{ route('expenses.edit', $e) }}" title="Edit expense" aria-label="Edit expense {{ $e->reference }}"><i class="fas fa-pen"></i></a>@endcan</td></tr>
    @empty <tr><td colspan="9" class="empty">No expenses.</td></tr> @endforelse</tbody>
    <tfoot><tr><td colspan="6">Total (filtered)</td><td class="num">{{ ugx($total) }}</td><td colspan="2"></td></tr></tfoot>
</table></div>
<div class="pagination">{{ $expenses->links() }}</div>
@endsection
