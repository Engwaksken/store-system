@extends('layouts.app')
@section('title', $title)
@section('content')
<div class="page-head"><div><h1>{{ $title }}</h1><p class="print-only">{{ $companyName }} · printed {{ now()->format('d M Y H:i') }} by {{ auth()->user()->name }}</p></div>
    <div class="actions"><button type="button" class="btn secondary" data-print><i class="fas fa-print"></i> Print</button><a class="btn secondary" href="{{ request()->fullUrlWithQuery(['format' => 'xlsx']) }}">Excel</a><a class="btn secondary" href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}">PDF</a><a class="btn secondary" href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}">CSV</a><a class="btn secondary" href="{{ route('reports.index') }}">All reports</a></div></div>
@if($filters)@include('partials.filters', ['fields' => $filters])@endif
@if($note)<div class="alert warn">{{ $note }}</div>@endif
<div class="card table-wrap"><table>
    <thead><tr>@foreach($headers as $i => $h)<th @class(['num' => in_array($i, $numeric)])>{{ $h }}</th>@endforeach</tr></thead>
    <tbody>@forelse($rows as $r)<tr>@foreach($r as $i => $v)<td @class(['num' => in_array($i, $numeric)])>{{ in_array($i, $numeric) && is_numeric($v) ? (in_array($i, $money) ? ugx($v) : qty($v)) : $v }}</td>@endforeach</tr>
    @empty<tr><td colspan="{{ count($headers) }}" class="empty">No data for this selection.</td></tr>@endforelse</tbody>
    @if($sum)<tfoot><tr>@foreach($headers as $i => $h)<td @class(['num' => in_array($i, $numeric)])>{{ $i === 0 ? 'Total' : (isset($sum[$i]) ? ugx($sum[$i]) : '') }}</td>@endforeach</tr></tfoot>@endif
</table></div>
@endsection
