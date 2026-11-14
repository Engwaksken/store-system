@extends('layouts.app')
@section('title', $project->exists ? 'Edit project' : 'New project')
@section('content')
<div class="page-head"><h1>{{ $project->exists ? 'Edit '.$project->code : 'New project' }}</h1></div>
<form method="POST" action="{{ $project->exists ? route('projects.update', $project) : route('projects.store') }}" class="card">
    @csrf @if($project->exists) @method('PUT') @endif
    <div class="form-grid">
        <div class="field"><label>Project code</label><input type="text" name="code" value="{{ old('code', $project->code) }}" required maxlength="30"><div class="hint">Unique, e.g. PRJ-2026-001</div></div>
        <div class="field"><label>Project name</label><input type="text" name="name" value="{{ old('name', $project->name) }}" required></div>
        <div class="field"><label>Client</label><input type="text" name="client" value="{{ old('client', $project->client) }}"></div>
        <div class="field"><label>Location</label><input type="text" name="location" value="{{ old('location', $project->location) }}"></div>
        <div class="field"><label>Start date</label><input type="date" name="start_date" value="{{ old('start_date', $project->start_date?->format('Y-m-d')) }}"></div>
        <div class="field"><label>End date</label><input type="date" name="end_date" value="{{ old('end_date', $project->end_date?->format('Y-m-d')) }}"></div>
        <div class="field"><label>Status</label><select name="status">@foreach(\App\Models\Project::STATUSES as $s)<option @selected(old('status', $project->status) === $s)>{{ $s }}</option>@endforeach</select></div>
        <div class="field"><label>Budget ({{ currency() }})</label><input type="number" name="budget" step="1" min="0" value="{{ old('budget', $project->budget ?? 0) }}" required></div>
        <div class="field"><label>Project Manager</label><select name="manager_id"><option value="">None</option>
            @foreach($managers as $m)<option value="{{ $m->id }}" @selected(old('manager_id', $project->manager_id) == $m->id)>{{ $m->name }}</option>@endforeach</select>
            <div class="hint">Material requests route to this person for the first approval.</div></div>
        <div class="field"><label>Assigned staff</label>
            @php($selected = collect(old('member_ids', $project->exists ? $project->members->pluck('id')->all() : [])))
            <div style="max-height:180px;overflow:auto;border:1px solid #CBD5E1;border-radius:6px;padding:.4rem">
            @foreach($staff as $s)<label class="check"><input type="checkbox" name="member_ids[]" value="{{ $s->id }}" @checked($selected->contains($s->id))> {{ $s->name }} <small class="muted">({{ $s->role->label() }})</small></label>@endforeach
            </div></div>
    </div>
    <div class="field"><label>Description</label><textarea name="description">{{ old('description', $project->description) }}</textarea></div>
    <button class="btn">Save project</button> <a class="btn secondary" href="{{ $project->exists ? route('projects.show', $project) : route('projects.index') }}">Cancel</a>
</form>
@endsection
