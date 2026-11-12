@extends('layouts.app')
@section('title', 'Settings')
@section('content')
<div class="page-head"><h1>Company settings</h1></div>
<div class="grid grid-2">
<div class="card">
<form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
    @csrf @method('PUT')
    <div class="field"><label>Company name</label><input type="text" name="company_name" value="{{ old('company_name', $values['company_name']) }}" required></div>
    <div class="field"><label>Address</label><input type="text" name="company_address" value="{{ old('company_address', $values['company_address']) }}"></div>
    <div class="field"><label>Phone</label><input type="text" name="company_phone" value="{{ old('company_phone', $values['company_phone']) }}"></div>
    <div class="field"><label>E-mail</label><input type="email" name="company_email" value="{{ old('company_email', $values['company_email']) }}"></div>
    <div class="field"><label>Voucher footer text</label><input type="text" name="voucher_footer" value="{{ old('voucher_footer', $values['voucher_footer']) }}"></div>
    <div class="field"><label>Logo (PNG/JPG, max 1 MB)</label><input type="file" name="logo" accept="image/png,image/jpeg"></div>
    <button class="btn">Save settings</button>
</form>
</div>
<div class="card">
    <h2>Expense categories</h2>
    <ul>@foreach($expenseCategories as $c)<li>{{ $c->name }}</li>@endforeach</ul>
    <form method="POST" action="{{ route('settings.expense-categories.store') }}">
        @csrf
        <div class="field"><label>New category</label><input type="text" name="name" required maxlength="100"></div>
        <button class="btn secondary">Add category</button>
    </form>
    <p class="hint">Do not create a category for store stock purchases: those are recorded as stock receipts and charged to projects when issued.</p>
</div>
</div>
@endsection
