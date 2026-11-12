@extends('layouts.app')
@section('title', 'Categories & units')
@section('content')
<div class="page-head"><h1>Categories &amp; units</h1></div>
<div class="grid grid-2">
    <div class="card"><h2>Item categories</h2>
        <table><tbody>@foreach($categories as $c)<tr><td>{{ $c->name }}</td><td class="num muted">{{ $c->items_count }} items</td></tr>@endforeach</tbody></table>
        <form method="POST" action="{{ route('catalog.categories.store') }}" style="margin-top:1rem">@csrf
            <div class="field"><label>New category</label><input type="text" name="name" required maxlength="100"></div><button class="btn secondary">Add</button></form>
    </div>
    <div class="card"><h2>Units of measure</h2>
        <table><tbody>@foreach($units as $u)<tr><td>{{ $u->name }}</td><td class="num">{{ $u->abbreviation }}</td></tr>@endforeach</tbody></table>
        <form method="POST" action="{{ route('catalog.units.store') }}" style="margin-top:1rem">@csrf
            <div class="form-grid"><div class="field"><label>Name</label><input type="text" name="name" required maxlength="50"></div>
            <div class="field"><label>Abbreviation</label><input type="text" name="abbreviation" required maxlength="15"></div></div><button class="btn secondary">Add</button></form>
    </div>
</div>
@endsection
