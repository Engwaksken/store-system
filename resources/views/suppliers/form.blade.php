@extends('layouts.app')
@section('title', $supplier->exists ? 'Edit '.$supplier->name : 'New supplier')
@section('content')
<div class="page-head"><h1>{{ $supplier->exists ? 'Edit supplier' : 'New supplier' }}</h1></div>
<form method="POST" action="{{ $supplier->exists ? route('suppliers.update', $supplier) : route('suppliers.store') }}" class="card" style="max-width:760px">
    @csrf @if($supplier->exists) @method('PUT') @endif
    <div class="form-grid">
        <div class="field"><label for="code">Code</label><input id="code" type="text" name="code" value="{{ old('code', $supplier->code) }}" maxlength="30" required></div>
        <div class="field"><label for="name">Name</label><input id="name" type="text" name="name" value="{{ old('name', $supplier->name) }}" required></div>
        <div class="field"><label for="contact_person">Contact person</label><input id="contact_person" type="text" name="contact_person" value="{{ old('contact_person', $supplier->contact_person) }}"></div>
        <div class="field"><label for="phone">Phone</label><input id="phone" type="text" name="phone" value="{{ old('phone', $supplier->phone) }}"></div>
        <div class="field"><label for="email">E-mail</label><input id="email" type="email" name="email" value="{{ old('email', $supplier->email) }}"></div>
        <div class="field"><label for="tin">TIN</label><input id="tin" type="text" name="tin" value="{{ old('tin', $supplier->tin) }}"></div>
        <div class="field"><label for="address">Address</label><input id="address" type="text" name="address" value="{{ old('address', $supplier->address) }}"></div>
        <div class="field"><label for="payment_terms">Payment terms</label><input id="payment_terms" type="text" name="payment_terms" value="{{ old('payment_terms', $supplier->payment_terms) }}" placeholder="e.g. 30 days"></div>
    </div>
    <input type="hidden" name="is_active" value="0"><label class="check field"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $supplier->is_active))> Active</label>
    <button class="btn">Save supplier</button>
</form>
@endsection
