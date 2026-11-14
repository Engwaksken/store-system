@extends('layouts.app')
@section('title', 'Cost codes')
@section('content')
<div class="page-head"><div><h1>Cost codes</h1><p>Company-wide codes for project budgets, BOQ lines, purchase orders and expenses.</p></div>
    @can('manage-cost-codes')<div class="actions"><button type="button" class="btn" data-modal-target="#cc-create"><i class="fas fa-plus"></i> Add cost code</button></div>@endcan</div>
<x-stats :cards="[
    ['Cost codes', $codes->count(), 'fa-hashtag', 'navy'],
    ['Active', $codes->where('is_active', true)->count(), 'fa-circle-check'],
    ['Inactive', $codes->where('is_active', false)->count(), 'fa-circle-pause', 'gold'],
    ['Categories used', $codes->pluck('category')->unique()->count(), 'fa-layer-group'],
]" />
<div class="card table-wrap"><table>
    <thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Status</th>@can('manage-cost-codes')<th></th>@endcan</tr></thead>
    <tbody>@forelse($codes as $c)
        <tr><td>{{ $c->code }}</td><td>{{ $c->name }}</td><td>{{ \App\Models\CostCode::CATEGORIES[$c->category] ?? $c->category }}</td>
            <td>@if($c->is_active)<span class="badge approved">Active</span>@else<span class="badge muted">Inactive</span>@endif</td>
            @can('manage-cost-codes')<td class="right"><button type="button" class="btn-icon" data-modal-target="#cc-edit-{{ $c->id }}" title="Edit {{ $c->code }}" aria-label="Edit {{ $c->code }}"><i class="fas fa-pen"></i></button>
                <template id="cc-edit-{{ $c->id }}" data-title="Edit cost code {{ $c->code }}">
                    <form method="POST" action="{{ route('cost-codes.update', $c) }}">@csrf @method('PUT')
                        <div class="field"><label for="cc_name_{{ $c->id }}">Name</label><input id="cc_name_{{ $c->id }}" type="text" name="name" value="{{ $c->name }}" required></div>
                        <div class="field"><label for="cc_cat_{{ $c->id }}">Category</label><select id="cc_cat_{{ $c->id }}" name="category">@foreach(\App\Models\CostCode::CATEGORIES as $k => $v)<option value="{{ $k }}" @selected($c->category === $k)>{{ $v }}</option>@endforeach</select></div>
                        <div class="field"><input type="hidden" name="is_active" value="0"><label class="check"><input type="checkbox" name="is_active" value="1" @checked($c->is_active)> Active</label>
                            <div class="hint">Inactive codes stay on old records but cannot be chosen for new ones.</div></div>
                        <button class="btn">Save</button>
                    </form>
                </template></td>@endcan</tr>
    @empty <tr><td colspan="5" class="empty">No cost codes yet.</td></tr> @endforelse</tbody>
</table></div>
@can('manage-cost-codes')
<template id="cc-create" data-title="Add cost code">
    <form method="POST" action="{{ route('cost-codes.store') }}">@csrf
        <div class="form-grid">
            <div class="field"><label for="code">Code</label><input id="code" type="text" name="code" required maxlength="20" placeholder="e.g. 03-CONC"></div>
            <div class="field"><label for="category">Category</label><select id="category" name="category">@foreach(\App\Models\CostCode::CATEGORIES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
        </div>
        <div class="field"><label for="name">Name</label><input id="name" type="text" name="name" required></div>
        <button class="btn">Add cost code</button>
    </form>
</template>
@endcan
@endsection
