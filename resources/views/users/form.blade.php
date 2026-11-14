@extends('layouts.app')
@section('title', $user->exists ? 'Edit user' : 'Add user')
@section('content')
<div class="page-head"><div><h1>{{ $user->exists ? 'Edit user' : 'Add user' }}</h1>
    <p>{{ $user->exists ? 'Change the role, projects or status. Users are deactivated, never deleted.' : 'The person signs in with the temporary password and must change it straight away.' }}</p></div></div>
<div class="card" style="max-width:760px">
<form method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}">
    @csrf @if($user->exists) @method('PUT') @endif
    <div class="form-grid">
        <div class="field"><label for="u_name">Full name</label><input id="u_name" type="text" name="name" value="{{ old('name', $user->name) }}" required placeholder="e.g. Brian Okello"></div>
        <div class="field"><label for="u_email">E-mail</label><input id="u_email" type="email" name="email" value="{{ old('email', $user->email) }}" required></div>
        <div class="field"><label for="u_phone">Phone</label><input id="u_phone" type="text" name="phone" value="{{ old('phone', $user->phone) }}" placeholder="e.g. +256 700 000000"></div>
        <div class="field"><label for="u_role">Role</label><select id="u_role" name="role" required data-user-role>
            @foreach(\App\Enums\Role::cases() as $r)<option value="{{ $r->value }}" @selected(old('role', $user->role?->value) === $r->value)>{{ $r->label() }}</option>@endforeach
        </select></div>
        <div class="field"><label for="u_password">{{ $user->exists ? 'Reset password (optional)' : 'Temporary password' }}</label><input id="u_password" type="password" name="password" autocomplete="new-password" @required(! $user->exists)>
            <div class="hint">At least 8 characters with letters and numbers. The user must change it at next sign-in.</div></div>
        <div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active))> Account active</label></div>
    </div>

    <fieldset class="field project-picker" data-modal-wide>
        <legend><i class="fas fa-diagram-project"></i> Projects</legend>
        <p class="hint" style="margin-top:0" data-role-hint>
            <strong>Staff</strong> and <strong>Project Managers</strong> only see, and can only request materials for, the projects ticked here.
            Other roles see every project; ticking a project adds them to its team list.
        </p>
        @if($projects->isEmpty())
            <p class="muted">No open projects yet. Create projects first under <a href="{{ route('projects.index') }}">Projects</a>, then assign people here.</p>
        @else
            @php($ticked = collect(old('project_ids', $assigned))->map(fn ($id) => (int) $id))
            <div class="picker-tools">
                <div class="input-icon"><i class="fas fa-magnifying-glass"></i><input type="text" placeholder="Find a project" data-picker-filter aria-label="Find a project"></div>
                <span class="muted"><strong data-picker-count>{{ $ticked->count() }}</strong> selected</span>
            </div>
            <div class="picker-list" data-picker>
                @foreach($projects as $p)
                    <label class="picker-item" data-picker-text="{{ strtolower($p->code.' '.$p->name) }}">
                        <input type="checkbox" name="project_ids[]" value="{{ $p->id }}" @checked($ticked->contains($p->id))>
                        <span><strong>{{ $p->code }}</strong> {{ $p->name }}
                            <small class="muted">· {{ str_replace('_', ' ', $p->status) }}@if($p->manager) · PM {{ $p->manager->name }}@endif</small></span>
                    </label>
                @endforeach
            </div>
        @endif
        @error('project_ids')<div class="err">{{ $message }}</div>@enderror
    </fieldset>

    <button class="btn">Save</button> <a class="btn secondary" href="{{ route('users.index') }}">Cancel</a>
</form>
</div>
@endsection
