@extends('layouts.app')
@section('title', 'Return against '.$issue->issue_no)
@section('content')
<div class="page-head"><div><h1>Return to store · {{ $issue->issue_no }}</h1><p>{{ $issue->project->code }} · issued {{ $issue->issued_at->format('d M Y') }} from {{ $issue->store->name }}. You can return up to what is still out on each line.</p></div></div>
<form method="POST" action="{{ route('returns.store', $issue) }}" class="card">
    @csrf @include('partials.idem')
    <div class="table-wrap"><table>
        <thead><tr><th>Item</th><th class="num">Issued</th><th class="num">Already returned</th><th class="num">Still out</th><th class="num">Return now</th></tr></thead>
        <tbody>@foreach($issue->lines as $l)
            @php($out = bcsub((string) $l->qty, $l->returnedQty(), 3))
            <tr><td>{{ $l->item->name }} <small class="muted">{{ $l->item->unit->abbreviation }}</small></td><td class="num">{{ qty($l->qty) }}</td><td class="num">{{ qty($l->returnedQty()) }}</td><td class="num">{{ qty($out) }}</td>
                <td class="num">@if((float) $out > 0)<input type="number" step="0.001" min="0" max="{{ $out }}" name="qty[{{ $l->id }}]" value="{{ old('qty.'.$l->id) }}" aria-label="Return {{ $l->item->sku }}">@else — @endif</td></tr>
        @endforeach</tbody>
    </table></div>
    <div class="form-grid">
        <div class="field"><label for="store_id">Receive into store</label><select id="store_id" name="store_id" required>
            @foreach($stores as $s)<option value="{{ $s->id }}" @selected(old('store_id', $issue->store_id) == $s->id)>{{ $s->label() }}</option>@endforeach</select></div>
        <div class="field"><label for="returned_by_name">Returned by (name)</label><input id="returned_by_name" type="text" name="returned_by_name" value="{{ old('returned_by_name', $issue->recipient_name) }}" required></div>
        <div class="field"><label for="reason">Reason / condition</label><input id="reason" type="text" name="reason" value="{{ old('reason') }}" required minlength="5"></div>
    </div>
    <button class="btn">Record return</button>
</form>
@endsection
