@extends('layouts.app')
@section('title', 'Cost codes')
@section('content')
<div class="page-head"><div><h1>Cost codes</h1><p>Company-wide codes for project budgets, BOQ lines, purchase orders and expenses.</p></div></div>
<div class="card table-wrap"><table>
    <thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Status</th>@can('manage-cost-codes')<th></th>@endcan</tr></thead>
    <tbody>@forelse($codes as $c)
        <tr>@can('manage-cost-codes')
            <td>{{ $c->code }}</td>
            <td colspan="4"><form method="POST" action="{{ route('cost-codes.update', $c) }}" class="grid grid-4" style="align-items:center">@csrf @method('PUT')
                <input type="text" name="name" value="{{ $c->name }}" required aria-label="Name for {{ $c->code }}">
                <select name="category" aria-label="Category for {{ $c->code }}">@foreach(\App\Models\CostCode::CATEGORIES as $k => $v)<option value="{{ $k }}" @selected($c->category === $k)>{{ $v }}</option>@endforeach</select>
                <label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($c->is_active)> Active</label>
                <button class="btn small secondary">Save</button></form></td>
        @else
            <td>{{ $c->code }}</td><td>{{ $c->name }}</td><td>{{ \App\Models\CostCode::CATEGORIES[$c->category] ?? $c->category }}</td><td>{{ $c->is_active ? 'Active' : 'Inactive' }}</td>
        @endcan</tr>
    @empty <tr><td colspan="5" class="empty">No cost codes yet.</td></tr> @endforelse</tbody>
</table></div>
@can('manage-cost-codes')
<form method="POST" action="{{ route('cost-codes.store') }}" class="card" style="max-width:760px">@csrf
    <h2>Add cost code</h2>
    <div class="grid grid-4">
        <div><label for="code">Code</label><input id="code" type="text" name="code" required maxlength="20" placeholder="e.g. 03-CONC"></div>
        <div style="grid-column:span 2"><label for="name">Name</label><input id="name" type="text" name="name" required></div>
        <div><label for="category">Category</label><select id="category" name="category">@foreach(\App\Models\CostCode::CATEGORIES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
    </div>
    <button class="btn" style="margin-top:.75rem">Add</button>
</form>
@endcan
@endsection
