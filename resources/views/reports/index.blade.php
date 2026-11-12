@extends('layouts.app')
@section('title', 'Reports')
@section('content')
<div class="page-head"><div><h1>Reports</h1><p>Every report can be printed or exported to CSV (opens in Excel).</p></div></div>
<div class="grid grid-3">
    <a class="card" href="{{ route('reports.stock') }}"><h3>Stock summary</h3><p class="muted">Balances, minimum levels, stock value.</p></a>
    @can('view-stock-ledger')<a class="card" href="{{ route('reports.movements') }}"><h3>Stock movement ledger</h3><p class="muted">Every receipt, issue and adjustment.</p></a>@endcan
    <a class="card" href="{{ route('reports.pending') }}"><h3>Pending approvals &amp; issues</h3><p class="muted">Who each request is waiting on, and for how long.</p></a>
    @can('view-finance-reports')
        <a class="card" href="{{ route('reports.issued') }}"><h3>Materials issued by project</h3><p class="muted">Quantities and value consumed.</p></a>
        <a class="card" href="{{ route('reports.expenses') }}"><h3>Cash expenses by project</h3><p class="muted">Totals per expense category.</p></a>
        <a class="card" href="{{ route('reports.project-cost') }}"><h3>Project budget vs cost</h3><p class="muted">Budget, materials, expenses, remaining.</p></a>
    @endcan
</div>
@endsection
