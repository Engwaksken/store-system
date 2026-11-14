@extends('layouts.app')
@section('title', 'Settings')
@section('content')
<div class="page-head"><div><h1>Company settings</h1><p>Branding, contact details and approval limits.</p></div></div>

<x-stats :cards="[
    ['Expense categories', $expenseCategories->count(), 'fa-tags', 'navy'],
    ['Logo', $logoPath ? 'Uploaded' : 'Not set', 'fa-image', $logoPath ? '' : 'gold'],
    ['Favicon', $faviconPath ? 'Uploaded' : 'Not set', 'fa-star', $faviconPath ? '' : 'gold'],
    ['PO owner limit (UGX)', ugx($values['po_owner_threshold'] ?? \App\Services\PurchasingService::DEFAULT_OWNER_THRESHOLD), 'fa-scale-balanced', 'gold'],
]" />

<div class="grid grid-2">
<div class="card">
<h2><i class="fas fa-building"></i> Company &amp; branding</h2>
<form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
    @csrf @method('PUT')
    <div class="field"><label for="company_name">Company name</label><input id="company_name" type="text" name="company_name" value="{{ old('company_name', $values['company_name']) }}" required placeholder="e.g. Kemmy Construction Ltd"></div>
    <div class="field"><label for="app_subtitle">Subtitle (under the name in the sidebar)</label><input id="app_subtitle" type="text" name="app_subtitle" maxlength="60" value="{{ old('app_subtitle', $values['app_subtitle']) }}" placeholder="Store & Projects"></div>
    <div class="field"><label for="company_address">Address</label><input id="company_address" type="text" name="company_address" value="{{ old('company_address', $values['company_address']) }}" placeholder="e.g. Plot 12, Kampala Road, Kampala"></div>
    <div class="form-grid">
        <div class="field"><label for="company_phone">Phone</label><input id="company_phone" type="text" name="company_phone" value="{{ old('company_phone', $values['company_phone']) }}" placeholder="e.g. +256 700 000000"></div>
        <div class="field"><label for="company_email">E-mail</label><input id="company_email" type="email" name="company_email" value="{{ old('company_email', $values['company_email']) }}" placeholder="e.g. info@company.co.ug"></div>
    </div>
    <div class="field"><label for="voucher_footer">Voucher footer text</label><input id="voucher_footer" type="text" name="voucher_footer" value="{{ old('voucher_footer', $values['voucher_footer']) }}" placeholder="Printed at the bottom of vouchers and POs"></div>
    <div class="field"><label for="po_owner_threshold">Purchase orders above this value (UGX) need the Owner/Admin</label><input id="po_owner_threshold" type="number" min="0" step="1" name="po_owner_threshold" value="{{ old('po_owner_threshold', $values['po_owner_threshold'] ?? \App\Services\PurchasingService::DEFAULT_OWNER_THRESHOLD) }}" placeholder="e.g. 5000000">
        <p class="hint">Below it, the Overall Manager may approve. Nobody approves a PO they raised.</p></div>

    <div class="form-grid">
        <div class="field"><label for="logo">Logo (PNG/JPG, max 1 MB)</label>
            @if($logoPath)<div class="brand-preview"><img src="{{ asset('storage/'.$logoPath) }}" alt="Current logo"></div>@endif
            <input id="logo" type="file" name="logo" accept="image/png,image/jpeg">
            @if($logoPath)<label class="check"><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label>@endif
            <p class="hint">Shown in the sidebar, on the sign-in page, vouchers and POs.</p></div>
        <div class="field"><label for="favicon">Favicon (PNG/ICO, max 256 KB)</label>
            @if($faviconPath)<div class="brand-preview"><img src="{{ asset('storage/'.$faviconPath) }}" alt="Current favicon" style="height:32px"></div>@endif
            <input id="favicon" type="file" name="favicon" accept="image/png,image/x-icon,.ico">
            @if($faviconPath)<label class="check"><input type="checkbox" name="remove_favicon" value="1"> Remove current favicon</label>@endif
            <p class="hint">The small icon in the browser tab. A square PNG of 64 x 64 works best.</p></div>
    </div>
    <button class="btn"><i class="fas fa-floppy-disk"></i> Save settings</button>
</form>
</div>
<div class="card">
    <div class="page-head" style="margin-bottom:.75rem"><h2 style="margin:0"><i class="fas fa-tags"></i> Expense categories</h2>
        <button type="button" class="btn small" data-modal-target="#expense-category-create"><i class="fas fa-plus"></i> Add category</button></div>
    <table><tbody>@forelse($expenseCategories as $c)<tr><td>{{ $c->name }}</td></tr>@empty<tr><td class="empty">No categories yet.</td></tr>@endforelse</tbody></table>
    <p class="hint">Do not create a category for store stock purchases: those are recorded as stock receipts and charged to projects when issued.</p>
</div>
</div>

<template id="expense-category-create" data-title="Add expense category">
    <form method="POST" action="{{ route('settings.expense-categories.store') }}">@csrf
        <div class="field"><label for="expense_category_name">Category name</label><input id="expense_category_name" type="text" name="name" required maxlength="100" placeholder="e.g. Transport"></div>
        <button class="btn">Add category</button>
    </form>
</template>
@endsection
