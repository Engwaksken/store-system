@extends('layouts.app')
@section('title', 'New stock count')
@section('content')
<div class="page-head"><div><h1>New stock count</h1><p>Creates a count sheet of every active item and freezes the book quantity now. Count, enter the figures, then submit for approval.</p></div></div>
<form method="POST" action="{{ route('counts.store') }}" class="card" style="max-width:640px">
    @csrf
    <div class="field"><label for="store_id">Store</label><select id="store_id" name="store_id" required>
        @foreach($stores as $s)<option value="{{ $s->id }}" @selected(old('store_id') == $s->id)>{{ $s->label() }}</option>@endforeach</select></div>
    <div class="field"><label for="notes">Notes</label><input id="notes" type="text" name="notes" value="{{ old('notes') }}" placeholder="e.g. Month-end count, November"></div>
    <button class="btn">Create count sheet</button>
</form>
@endsection
