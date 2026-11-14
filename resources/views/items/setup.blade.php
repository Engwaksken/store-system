@extends('layouts.app')
@section('title', 'Categories & units')
@section('content')
<div class="page-head"><h1>Categories &amp; units</h1></div>
<div class="grid grid-2">
    <div class="card">
        <div class="page-head" style="margin-bottom:.75rem"><h2 style="margin:0"><i class="fas fa-tags"></i> Item categories</h2>
            <button type="button" class="btn small" data-modal-target="#category-create"><i class="fas fa-plus"></i> Add category</button></div>
        <table><tbody>@forelse($categories as $c)<tr><td>{{ $c->name }}</td><td class="num muted">{{ $c->items_count }} items</td></tr>
            @empty <tr><td class="empty">No categories yet.</td></tr> @endforelse</tbody></table>
    </div>
    <div class="card">
        <div class="page-head" style="margin-bottom:.75rem"><h2 style="margin:0"><i class="fas fa-ruler-combined"></i> Units of measure</h2>
            <button type="button" class="btn small" data-modal-target="#unit-create"><i class="fas fa-plus"></i> Add unit</button></div>
        <table><tbody>@forelse($units as $u)<tr><td>{{ $u->name }}</td><td class="num">{{ $u->abbreviation }}</td></tr>
            @empty <tr><td class="empty">No units yet.</td></tr> @endforelse</tbody></table>
    </div>
</div>

<template id="category-create" data-title="Add item category">
    <form method="POST" action="{{ route('catalog.categories.store') }}">@csrf
        <div class="field"><label for="category_name">Category name</label><input id="category_name" type="text" name="name" required maxlength="100"></div>
        <button class="btn">Add category</button>
    </form>
</template>
<template id="unit-create" data-title="Add unit of measure">
    <form method="POST" action="{{ route('catalog.units.store') }}">@csrf
        <div class="form-grid">
            <div class="field"><label for="unit_name">Name</label><input id="unit_name" type="text" name="name" required maxlength="50" placeholder="e.g. Bag"></div>
            <div class="field"><label for="unit_abbr">Abbreviation</label><input id="unit_abbr" type="text" name="abbreviation" required maxlength="15" placeholder="e.g. bag"></div>
        </div>
        <button class="btn">Add unit</button>
    </form>
</template>
@endsection
