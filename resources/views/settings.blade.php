@extends('layouts.app')
@section('title', 'Settings')
@section('content')
<div class="page-head"><div><h1>Company settings</h1><p>Branding, contact details, currency and approval limits.</p></div></div>

<x-stats :cards="[
    ['Currency', $currentCurrency, 'fa-money-bill-wave', 'navy'],
    ['Expense categories', $expenseCategories->count(), 'fa-tags'],
    ['Logo / favicon', ($logoPath ? 'Logo' : 'No logo').' · '.($faviconPath ? 'Favicon' : 'No favicon'), 'fa-image', $logoPath && $faviconPath ? '' : 'gold'],
    ['PO owner limit ('.currency().')', ugx($values['po_owner_threshold'] ?? \App\Services\PurchasingService::DEFAULT_OWNER_THRESHOLD), 'fa-scale-balanced', 'gold'],
]" />

<form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
    @csrf @method('PUT')
<x-tabs id="settings">
    <x-tab title="Company" icon="fa-building" name="company">
        <div class="settings-pane">
            <div class="field"><label for="company_name">Company name</label><input id="company_name" type="text" name="company_name" value="{{ old('company_name', $values['company_name']) }}" required placeholder="e.g. Kemmy Construction Ltd"></div>
            <div class="field"><label for="company_address">Address</label><input id="company_address" type="text" name="company_address" value="{{ old('company_address', $values['company_address']) }}" placeholder="e.g. Plot 12, Kampala Road, Kampala"></div>
            <div class="form-grid">
                <div class="field"><label for="company_phone">Phone</label><input id="company_phone" type="text" name="company_phone" value="{{ old('company_phone', $values['company_phone']) }}" placeholder="e.g. +256 700 000000"></div>
                <div class="field"><label for="company_email">E-mail</label><input id="company_email" type="email" name="company_email" value="{{ old('company_email', $values['company_email']) }}" placeholder="e.g. info@company.co.ug"></div>
            </div>
            <div class="field"><label for="voucher_footer">Voucher footer text</label><input id="voucher_footer" type="text" name="voucher_footer" value="{{ old('voucher_footer', $values['voucher_footer']) }}" placeholder="Printed at the bottom of vouchers and POs"></div>
        </div>
    </x-tab>

    <x-tab title="Branding" icon="fa-palette" name="branding">
        <div class="settings-pane">
            <div class="field"><label for="app_subtitle">Subtitle (under the name in the sidebar)</label><input id="app_subtitle" type="text" name="app_subtitle" maxlength="60" value="{{ old('app_subtitle', $values['app_subtitle']) }}" placeholder="Store & Projects"></div>
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
        </div>
    </x-tab>

    <x-tab title="Currency & approvals" icon="fa-coins" name="finance">
        <div class="settings-pane">
            <div class="field"><label for="currency">Company currency</label>
                <select id="currency" name="currency" required>
                    @foreach(\App\Support\Currency::LIST as $code => [$label, $decimals])
                        <option value="{{ $code }}" @selected(old('currency', $currentCurrency) === $code)>{{ $code }} - {{ $label }}</option>
                    @endforeach
                </select>
                <p class="hint">Used for every amount label, voucher, PO, report and export. Changing it relabels amounts only: stored figures are <strong>not</strong> converted, so set it once before go-live.</p></div>
            <div class="field"><label for="po_owner_threshold">Purchase orders above this value ({{ currency() }}) need the Owner/Admin</label><input id="po_owner_threshold" type="number" min="0" step="1" name="po_owner_threshold" value="{{ old('po_owner_threshold', $values['po_owner_threshold'] ?? \App\Services\PurchasingService::DEFAULT_OWNER_THRESHOLD) }}" placeholder="e.g. 5,000,000">
                <p class="hint">Below it, the Overall Manager may approve. Nobody approves a PO they raised.</p></div>
        </div>
    </x-tab>

    <x-tab title="Expense categories" icon="fa-tags" name="categories" :count="$expenseCategories->count()">
        <div class="actions" style="margin-bottom:.75rem"><button type="button" class="btn small" data-modal-target="#expense-category-create"><i class="fas fa-plus"></i> Add category</button></div>
        <table style="max-width:520px"><tbody>@forelse($expenseCategories as $c)<tr><td><i class="fas fa-tag muted"></i> {{ $c->name }}</td></tr>@empty<tr><td class="empty">No categories yet.</td></tr>@endforelse</tbody></table>
        <p class="hint">Do not create a category for store stock purchases: those are recorded as stock receipts and charged to projects when issued.</p>
    </x-tab>
</x-tabs>
    <div class="form-save"><button class="btn"><i class="fas fa-floppy-disk"></i> Save settings</button></div>
</form>

<template id="expense-category-create" data-title="Add expense category">
    <form method="POST" action="{{ route('settings.expense-categories.store') }}">@csrf
        <div class="field"><label for="expense_category_name">Category name</label><input id="expense_category_name" type="text" name="name" required maxlength="100" placeholder="e.g. Transport"></div>
        <button class="btn">Add category</button>
    </form>
</template>
@endsection
