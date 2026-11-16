@extends('layouts.app')
@section('title', 'Manage roles')
@section('content')
<div class="page-head"><div><h1>Manage roles</h1><p>Custom role names reuse an existing permission profile. Changes to a profile affect every user assigned that role.</p></div><a class="btn secondary" href="{{ route('users.index') }}">Back to users</a></div>
<div class="card" style="max-width:760px;margin-bottom:1rem"><h2>Add custom role</h2>
    <form method="POST" action="{{ route('users.roles.store') }}" class="form-grid">@csrf
        <div class="field"><label for="role_name">Role name</label><input id="role_name" name="name" required maxlength="80" value="{{ old('name') }}" placeholder="e.g. Warehouse Supervisor">@error('name')<div class="err">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="profile">Permission profile</label><select id="profile" name="permission_profile" required>@foreach(\App\Enums\Role::cases() as $r)<option value="{{ $r->value }}" @selected(old('permission_profile') === $r->value)>{{ $r->label() }}</option>@endforeach</select>@error('permission_profile')<div class="err">{{ $message }}</div>@enderror</div>
        <div><button class="btn"><i class="fas fa-plus"></i> Add role</button></div>
    </form>
</div>
<div class="card table-wrap"><table><thead><tr><th>Name</th><th>Permission profile</th><th class="num">Users</th><th></th></tr></thead><tbody>
@forelse($roles as $role)<tr><td>{{ $role->name }}</td><td>{{ \App\Enums\Role::from($role->permission_profile)->label() }}</td><td class="num">{{ $role->users_count }}</td><td class="right">
    <details><summary>Edit</summary><form method="POST" action="{{ route('users.roles.update', $role) }}" class="form-grid">@csrf @method('PUT')<div class="field"><label>Name</label><input name="name" required maxlength="80" value="{{ $role->name }}"></div><div class="field"><label>Permission profile</label><select name="permission_profile">@foreach(\App\Enums\Role::cases() as $r)<option value="{{ $r->value }}" @selected($role->permission_profile === $r->value)>{{ $r->label() }}</option>@endforeach</select></div><button class="btn small">Save</button></form></details>
    @if(!$role->users_count)<form method="POST" action="{{ route('users.roles.destroy', $role) }}" class="inline-form" data-confirm="Delete this unused custom role?">@csrf @method('DELETE')<button class="btn small danger">Delete</button></form>@endif
</td></tr>@empty<tr><td colspan="4" class="empty">No custom roles yet.</td></tr>@endforelse
</tbody></table></div>
@endsection
