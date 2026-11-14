@extends('layouts.app')
@section('title', 'Catalog setup')
@section('content')
@php($canExpense = auth()->user()->can('manage-settings'))
<div class="page-head"><div><h1>Catalog setup</h1><p>Item categories and units of measure used by the stock catalogue@if($canExpense), and the categories used for project expenses and claims@endif. A record still in use cannot be deleted.</p></div></div>
<x-stats :cards="array_values(array_filter([
    ['Item categories', $categories->count(), 'fa-tags', 'navy'],
    ['Units of measure', $units->count(), 'fa-ruler-combined'],
    ['Items catalogued', $categories->sum('items_count'), 'fa-boxes-stacked', 'gold'],
    $canExpense ? ['Expense categories', $expenseCategories->count(), 'fa-receipt'] : null,
]))" />

<x-tabs id="catalog-setup">
    {{-- Item categories --}}
    <x-tab title="Item categories" icon="fa-tags" name="item-categories" :count="$categories->count()">
        <div class="card table-wrap" data-bulk-scope="categories">
            <div class="page-head" style="margin-bottom:.75rem"><h2 style="margin:0">Item categories</h2>
                <button type="button" class="btn small" data-modal-target="#category-create"><i class="fas fa-plus"></i> Add category</button></div>
            <form method="POST" action="{{ route('catalog.setup.bulk-delete', 'categories') }}" class="bulk-bar" data-bulk-bar data-bulk-form="categories" hidden>@csrf
                <span class="bulk-count"><i class="fas fa-square-check"></i> <strong data-bulk-count>0</strong> selected</span>
                <span class="actions">
                    <button class="btn small danger" data-confirm="Delete the selected categories? Categories that still have items are skipped and listed." data-confirm-ok="Yes, delete"><i class="fas fa-trash-can"></i> Delete</button>
                    <button type="button" class="btn small link" data-bulk-clear>Clear</button>
                </span>
            </form>
            <table class="data-table">
                <thead><tr><th class="check-col"><input type="checkbox" data-check-all aria-label="Select all categories on this page"></th><th>Name</th><th class="num">Items</th><th class="right">Actions</th></tr></thead>
                <tbody>@forelse($categories as $c)
                    <tr>
                        <td class="check-col"><input type="checkbox" value="{{ $c->id }}" data-row-check aria-label="Select {{ $c->name }}"></td>
                        <td><strong>{{ $c->name }}</strong></td>
                        <td class="num">@if($c->items_count)<a href="{{ route('items.index', ['category_id' => $c->id]) }}">{{ $c->items_count }}</a>@else<span class="muted">0</span>@endif</td>
                        <td><div class="row-actions">
                            <button type="button" class="btn-icon" data-modal-target="#category-edit-{{ $c->id }}" title="Edit {{ $c->name }}" aria-label="Edit {{ $c->name }}"><i class="fas fa-pen"></i></button>
                            <form method="POST" action="{{ route('catalog.setup.destroy', ['categories', $c->id]) }}" class="inline-form">@csrf @method('DELETE')
                                <button class="btn-icon danger" @disabled($c->items_count) title="{{ $c->items_count ? 'In use by '.$c->items_count.' item(s)' : 'Delete '.$c->name }}" aria-label="Delete {{ $c->name }}" data-confirm="Delete category {{ $c->name }}?" data-confirm-ok="Yes, delete"><i class="fas fa-trash-can"></i></button></form>
                        </div>
                        <template id="category-edit-{{ $c->id }}" data-title="Edit item category">
                            <form method="POST" action="{{ route('catalog.categories.update', $c) }}">@csrf @method('PUT')
                                <div class="field"><label for="category_name_{{ $c->id }}">Category name</label><input id="category_name_{{ $c->id }}" type="text" name="name" value="{{ $c->name }}" required maxlength="100"></div>
                                <button class="btn"><i class="fas fa-floppy-disk"></i> Save</button>
                            </form>
                        </template></td>
                    </tr>
                @empty <tr><td colspan="4" class="empty">No categories yet.</td></tr> @endforelse</tbody>
            </table>
        </div>
    </x-tab>

    {{-- Units of measure --}}
    <x-tab title="Units of measure" icon="fa-ruler-combined" name="units" :count="$units->count()">
        <div class="card table-wrap" data-bulk-scope="units">
            <div class="page-head" style="margin-bottom:.75rem"><h2 style="margin:0">Units of measure</h2>
                <button type="button" class="btn small" data-modal-target="#unit-create"><i class="fas fa-plus"></i> Add unit</button></div>
            <form method="POST" action="{{ route('catalog.setup.bulk-delete', 'units') }}" class="bulk-bar" data-bulk-bar data-bulk-form="units" hidden>@csrf
                <span class="bulk-count"><i class="fas fa-square-check"></i> <strong data-bulk-count>0</strong> selected</span>
                <span class="actions">
                    <button class="btn small danger" data-confirm="Delete the selected units? Units that items still use are skipped and listed." data-confirm-ok="Yes, delete"><i class="fas fa-trash-can"></i> Delete</button>
                    <button type="button" class="btn small link" data-bulk-clear>Clear</button>
                </span>
            </form>
            <table class="data-table">
                <thead><tr><th class="check-col"><input type="checkbox" data-check-all aria-label="Select all units on this page"></th><th>Name</th><th>Abbreviation</th><th class="num">Items</th><th class="right">Actions</th></tr></thead>
                <tbody>@forelse($units as $u)
                    <tr>
                        <td class="check-col"><input type="checkbox" value="{{ $u->id }}" data-row-check aria-label="Select {{ $u->name }}"></td>
                        <td><strong>{{ $u->name }}</strong></td>
                        <td><code>{{ $u->abbreviation }}</code></td>
                        <td class="num">@if($u->items_count){{ $u->items_count }}@else<span class="muted">0</span>@endif</td>
                        <td><div class="row-actions">
                            <button type="button" class="btn-icon" data-modal-target="#unit-edit-{{ $u->id }}" title="Edit {{ $u->name }}" aria-label="Edit {{ $u->name }}"><i class="fas fa-pen"></i></button>
                            <form method="POST" action="{{ route('catalog.setup.destroy', ['units', $u->id]) }}" class="inline-form">@csrf @method('DELETE')
                                <button class="btn-icon danger" @disabled($u->items_count) title="{{ $u->items_count ? 'In use by '.$u->items_count.' item(s)' : 'Delete '.$u->name }}" aria-label="Delete {{ $u->name }}" data-confirm="Delete unit {{ $u->name }} ({{ $u->abbreviation }})?" data-confirm-ok="Yes, delete"><i class="fas fa-trash-can"></i></button></form>
                        </div>
                        <template id="unit-edit-{{ $u->id }}" data-title="Edit unit of measure">
                            <form method="POST" action="{{ route('catalog.units.update', $u) }}">@csrf @method('PUT')
                                <div class="form-grid">
                                    <div class="field"><label for="unit_name_{{ $u->id }}">Name</label><input id="unit_name_{{ $u->id }}" type="text" name="name" value="{{ $u->name }}" required maxlength="50"></div>
                                    <div class="field"><label for="unit_abbr_{{ $u->id }}">Abbreviation</label><input id="unit_abbr_{{ $u->id }}" type="text" name="abbreviation" value="{{ $u->abbreviation }}" required maxlength="15">
                                        <p class="hint">Item imports (CSV) refer to units by this abbreviation.</p></div>
                                </div>
                                <button class="btn"><i class="fas fa-floppy-disk"></i> Save</button>
                            </form>
                        </template></td>
                    </tr>
                @empty <tr><td colspan="5" class="empty">No units yet.</td></tr> @endforelse</tbody>
            </table>
        </div>
    </x-tab>

    {{-- Expense categories (Owner/Admin) --}}
    @if($canExpense)
    <x-tab title="Expense categories" icon="fa-receipt" name="expense-categories" :count="$expenseCategories->count()">
        <div class="card table-wrap" data-bulk-scope="expense-categories">
            <div class="page-head" style="margin-bottom:.75rem"><div><h2 style="margin:0">Expense categories</h2>
                <p class="hint" style="margin:.25rem 0 0">Used on project expenses and staff claims. Do not create one for store stock purchases: those are stock receipts, charged to projects when issued.</p></div>
                <button type="button" class="btn small" data-modal-target="#expense-category-create"><i class="fas fa-plus"></i> Add category</button></div>
            <form method="POST" action="{{ route('catalog.setup.bulk-delete', 'expense-categories') }}" class="bulk-bar" data-bulk-bar data-bulk-form="expense-categories" hidden>@csrf
                <span class="bulk-count"><i class="fas fa-square-check"></i> <strong data-bulk-count>0</strong> selected</span>
                <span class="actions">
                    <button class="btn small danger" data-confirm="Delete the selected expense categories? Categories used by an expense or claim are skipped and listed (deactivate those instead)." data-confirm-ok="Yes, delete"><i class="fas fa-trash-can"></i> Delete</button>
                    <button type="button" class="btn small link" data-bulk-clear>Clear</button>
                </span>
            </form>
            <table class="data-table">
                <thead><tr><th class="check-col"><input type="checkbox" data-check-all aria-label="Select all expense categories on this page"></th><th>Name</th><th>Status</th><th class="num">Expenses</th><th class="num">Claims</th><th class="right">Actions</th></tr></thead>
                <tbody>@forelse($expenseCategories as $c)
                    @php($used = $c->expenses_count + $c->claims_count)
                    <tr>
                        <td class="check-col"><input type="checkbox" value="{{ $c->id }}" data-row-check aria-label="Select {{ $c->name }}"></td>
                        <td><strong>{{ $c->name }}</strong></td>
                        <td>@if($c->is_active)<span class="badge approved">Active</span>@else<span class="badge muted">Inactive</span>@endif</td>
                        <td class="num">{{ $c->expenses_count }}</td>
                        <td class="num">{{ $c->claims_count }}</td>
                        <td><div class="row-actions">
                            <button type="button" class="btn-icon" data-modal-target="#expense-category-edit-{{ $c->id }}" title="Edit {{ $c->name }}" aria-label="Edit {{ $c->name }}"><i class="fas fa-pen"></i></button>
                            <form method="POST" action="{{ route('catalog.setup.destroy', ['expense-categories', $c->id]) }}" class="inline-form">@csrf @method('DELETE')
                                <button class="btn-icon danger" @disabled($used) title="{{ $used ? 'In use; edit it and untick Active instead' : 'Delete '.$c->name }}" aria-label="Delete {{ $c->name }}" data-confirm="Delete expense category {{ $c->name }}?" data-confirm-ok="Yes, delete"><i class="fas fa-trash-can"></i></button></form>
                        </div>
                        <template id="expense-category-edit-{{ $c->id }}" data-title="Edit expense category">
                            <form method="POST" action="{{ route('catalog.expense-categories.update', $c) }}">@csrf @method('PUT')
                                <div class="field"><label for="ec_name_{{ $c->id }}">Category name</label><input id="ec_name_{{ $c->id }}" type="text" name="name" value="{{ $c->name }}" required maxlength="100"></div>
                                <div class="field"><label class="check"><input type="checkbox" name="is_active" value="1" @checked($c->is_active)> Active <span class="muted">(inactive categories cannot be chosen on new expenses or claims)</span></label></div>
                                <button class="btn"><i class="fas fa-floppy-disk"></i> Save</button>
                            </form>
                        </template></td>
                    </tr>
                @empty <tr><td colspan="6" class="empty">No expense categories yet.</td></tr> @endforelse</tbody>
            </table>
        </div>
    </x-tab>
    @endif
</x-tabs>

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
@if($canExpense)
<template id="expense-category-create" data-title="Add expense category">
    <form method="POST" action="{{ route('catalog.expense-categories.store') }}">@csrf
        <div class="field"><label for="expense_category_name">Category name</label><input id="expense_category_name" type="text" name="name" required maxlength="100" placeholder="e.g. Transport"></div>
        <button class="btn">Add category</button>
    </form>
</template>
@endif
@endsection
