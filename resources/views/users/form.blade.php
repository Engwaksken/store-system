@extends('layouts.app')
@section('title', $user->exists ? 'Edit user' : 'Add user')
@section('content')
<div class="page-head"><h1>{{ $user->exists ? 'Edit user' : 'Add user' }}</h1></div>
<div class="card" style="max-width:720px">
<form method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}">
    @csrf @if($user->exists) @method('PUT') @endif
    <div class="form-grid">
        <div class="field"><label>Full name</label><input type="text" name="name" value="{{ old('name', $user->name) }}" required></div>
        <div class="field"><label>E-mail</label><input type="email" name="email" value="{{ old('email', $user->email) }}" required></div>
        <div class="field"><label>Phone</label><input type="text" name="phone" value="{{ old('phone', $user->phone) }}"></div>
        <div class="field"><label>Role</label><select name="role" required>
            @foreach(\App\Enums\Role::cases() as $r)<option value="{{ $r->value }}" @selected(old('role', $user->role?->value) === $r->value)>{{ $r->label() }}</option>@endforeach
        </select></div>
        <div class="field"><label>{{ $user->exists ? 'Reset password (optional)' : 'Temporary password' }}</label><input type="password" name="password" autocomplete="new-password" @required(! $user->exists)>
            <div class="hint">The user must change it at next sign-in.</div></div>
        <div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active))> Account active</label></div>
    </div>
    <button class="btn">Save</button> <a class="btn secondary" href="{{ route('users.index') }}">Cancel</a>
</form>
</div>
@endsection
