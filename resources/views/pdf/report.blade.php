@extends('pdf.layout')
@section('title', $title)
@section('doc-head')<h1>{{ $title }}</h1>@if($filterText)<div class="muted">{{ $filterText }}</div>@endif @endsection
@section('body')
@if($note)<div class="note">{{ $note }}</div>@endif
<table class="grid">
    <thead><tr>@foreach($headers as $i => $h)<th @class(['num' => in_array($i, $numeric)])>{{ $h }}</th>@endforeach</tr></thead>
    <tbody>@forelse($rows as $r)<tr>@foreach($r as $i => $v)<td @class(['num' => in_array($i, $numeric)])>{{ in_array($i, $numeric) && is_numeric($v) ? (in_array($i, $money) ? ugx($v) : qty($v)) : $v }}</td>@endforeach</tr>
    @empty<tr><td colspan="{{ count($headers) }}" class="muted">No rows.</td></tr>@endforelse</tbody>
    @if($sum)<tfoot><tr>@foreach($headers as $i => $h)<td @class(['num' => in_array($i, $numeric)])>{{ $i === 0 ? 'Total' : (isset($sum[$i]) ? ugx($sum[$i]) : '') }}</td>@endforeach</tr></tfoot>@endif
</table>
@endsection
