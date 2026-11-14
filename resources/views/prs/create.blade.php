@extends('layouts.app')
@section('title', 'New purchase requisition')
@section('content')
<div class="page-head"><div><h1>New purchase requisition</h1><p>Leave the project empty to restock the store.</p></div></div>
<form method="POST" action="{{ route('prs.store') }}" class="card">
    @csrf
    <div class="form-grid">
        <div class="field"><label for="project_id">Project</label><select id="project_id" name="project_id"><option value="">- Store stock -</option>
            @foreach($projects as $p)<option value="{{ $p->id }}" @selected(old('project_id') == $p->id)>{{ $p->code }} - {{ $p->name }}</option>@endforeach</select></div>
        <div class="field"><label for="store_id">Deliver to store</label><select id="store_id" name="store_id" required>
            @foreach($stores as $s)<option value="{{ $s->id }}" @selected(old('store_id') == $s->id)>{{ $s->label() }}</option>@endforeach</select></div>
        <div class="field"><label for="purpose">Purpose</label><input id="purpose" type="text" name="purpose" value="{{ old('purpose') }}" required></div>
        <div class="field"><label for="needed_on">Needed by</label><input id="needed_on" type="date" name="needed_on" value="{{ old('needed_on') }}"></div>
    </div>
    <div class="table-wrap"><table id="lines">
        <thead><tr><th>Item</th><th class="num">Quantity</th><th class="num">Est. unit cost (UGX)</th></tr></thead>
        <tbody>@for($n = 0; $n < max(3, count(old('lines', []))); $n++)
            <tr><td><select name="lines[{{ $n }}][item_id]" aria-label="Item"><option value="">None</option>@foreach($items as $i)<option value="{{ $i->id }}" @selected(old("lines.$n.item_id") == $i->id)>{{ $i->sku }} - {{ $i->name }} ({{ $i->unit->abbreviation }})</option>@endforeach</select></td>
                <td><input type="number" step="0.001" min="0" name="lines[{{ $n }}][qty]" value="{{ old("lines.$n.qty") }}" aria-label="Quantity"></td>
                <td><input type="number" step="0.01" min="0" name="lines[{{ $n }}][est_unit_cost]" value="{{ old("lines.$n.est_unit_cost") }}" aria-label="Estimated unit cost"></td></tr>
        @endfor</tbody>
    </table></div>
    <p><button type="button" class="btn secondary small" data-add-line="#lines"><i class="fas fa-plus"></i> Add line</button></p>
    <div class="actions"><button class="btn secondary" name="action" value="draft">Save draft</button><button class="btn" name="action" value="submit">Submit for approval</button></div>
</form>
@include('partials.add-line')
@endsection
