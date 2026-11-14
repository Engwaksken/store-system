@extends('layouts.app')
@section('title', $return->return_no)
@section('content')
<div class="page-head"><div><h1>Return {{ $return->return_no }}</h1><p>{{ $return->returned_at->format('d M Y H:i') }} · into {{ $return->store->label() }}</p></div>
    <button type="button" class="btn secondary no-print" data-print><i class="fas fa-print"></i> Print</button></div>
<div class="no-print">
<x-stats :cards="[
    ['Lines', number_format($return->lines->count()), 'fa-list', 'navy'],
    ['Project credit ('.currency().')', ugx($return->total_value), 'fa-hand-holding-dollar'],
    ['Project', $return->project->code, 'fa-person-digging', 'gold'],
    ['Voucher', $return->issue->issue_no, 'fa-file-lines', '', route('issues.show', $return->issue)],
]" />
</div>
<x-tabs id="return-show">
    <x-tab title="Lines" icon="fa-list" :count="$return->lines->count()" name="lines">
        <div class="table-wrap"><table>
            <thead><tr><th>SKU</th><th>Item</th><th class="num">Qty</th><th>Unit</th><th class="num">Unit cost</th><th class="num">Credit ({{ currency() }})</th></tr></thead>
            <tbody>@foreach($return->lines as $l)<tr><td>{{ $l->item->sku }}</td><td>{{ $l->item->name }}</td><td class="num">{{ qty($l->qty) }}</td><td>{{ $l->item->unit->abbreviation }}</td><td class="num">{{ ugx($l->unit_cost) }}</td><td class="num">{{ ugx($l->line_value) }}</td></tr>@endforeach</tbody>
            <tfoot><tr><td colspan="5">Project credit</td><td class="num">{{ ugx($return->total_value) }}</td></tr></tfoot>
        </table></div>
    </x-tab>
    <x-tab title="Details" icon="fa-circle-info" name="details">
        <dl class="meta">
            <dt>Voucher</dt><dd><a href="{{ route('issues.show', $return->issue) }}">{{ $return->issue->issue_no }}</a></dd>
            <dt>Project</dt><dd>{{ $return->project->code }} · {{ $return->project->name }}</dd>
            <dt>Returned by</dt><dd>{{ $return->returned_by_name }}</dd>
            <dt>Received by</dt><dd>{{ $return->receiver->name }}</dd>
            <dt>Reason</dt><dd>{{ $return->reason }}</dd>
        </dl>
    </x-tab>
</x-tabs>
<div class="signatures print-only"><div>Returned by</div><div>Received by (storekeeper)</div><div>Date</div></div>
@endsection
