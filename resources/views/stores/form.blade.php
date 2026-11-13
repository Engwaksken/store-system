@extends('layouts.app')
@section('title', $store->exists ? 'Edit '.$store->code : 'New store')
@section('content')
<div class="page-head"><div><h1>{{ $store->exists ? 'Edit store '.$store->code : 'New store' }}</h1>
    <p>A site store belongs to one project and can only issue to that project.</p></div></div>
<form method="POST" action="{{ $store->exists ? route('stores.update', $store) : route('stores.store') }}" class="card" style="max-width:760px">
    @csrf @if($store->exists) @method('PUT') @endif
    <div class="form-grid">
        <div class="field"><label for="code">Code</label><input id="code" type="text" name="code" value="{{ old('code', $store->code) }}" maxlength="30" required></div>
        <div class="field"><label for="name">Name</label><input id="name" type="text" name="name" value="{{ old('name', $store->name) }}" required></div>
        <div class="field"><label for="type">Type</label><select id="type" name="type" @disabled($store->id === \App\Models\Store::DEFAULT_ID)>
            @foreach(\App\Models\Store::TYPES as $k => $label)<option value="{{ $k }}" @selected(old('type', $store->type) === $k)>{{ $label }}</option>@endforeach</select></div>
        <div class="field"><label for="project_id">Project (site stores only)</label><select id="project_id" name="project_id"><option value="">—</option>
            @foreach($projects as $p)<option value="{{ $p->id }}" @selected(old('project_id', $store->project_id) == $p->id)>{{ $p->code }} – {{ $p->name }}</option>@endforeach</select></div>
        <div class="field"><label for="location">Location</label><input id="location" type="text" name="location" value="{{ old('location', $store->location) }}"></div>
        <div class="field"><label>&nbsp;</label><input type="hidden" name="is_active" value="0">
            <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $store->is_active)) @disabled($store->id === \App\Models\Store::DEFAULT_ID)> Active</label>
            @if($store->id === \App\Models\Store::DEFAULT_ID)<input type="hidden" name="is_active" value="1"><p class="hint">The main store is always active.</p>@endif</div>
    </div>
    @if(auth()->user()->isAdmin())
        <fieldset class="field"><legend>Assigned storekeepers</legend>
            <input type="hidden" name="user_ids" value="">
            @forelse($storekeepers as $u)
                <label class="check"><input type="checkbox" name="user_ids[]" value="{{ $u->id }}" @checked(in_array($u->id, old('user_ids', $store->users?->pluck('id')->all() ?? [])))> {{ $u->name }}</label>
            @empty <p class="muted">No active storekeepers yet.</p> @endforelse
        </fieldset>
    @endif
    <button class="btn">Save store</button>
</form>
@endsection
