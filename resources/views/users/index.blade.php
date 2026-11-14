@extends('layouts.app')
@section('title', 'Users')
@section('content')
<div class="page-head"><h1>Users</h1><a class="btn" data-modal href="{{ route('users.create') }}"><i class="fas fa-plus"></i> Add user</a></div>
<x-stats :cards="[
    ['Users', $stats['total'], 'fa-users', 'navy'],
    ['Active', $stats['active'], 'fa-user-check'],
    ['Inactive', $stats['inactive'], 'fa-user-slash', 'gold'],
    ['Active admins', $stats['admins'], 'fa-user-shield'],
]" />
<x-list-filters :list="$list">
    <div><label for="f_role">Role</label><select id="f_role" name="role"><option value="">All roles</option>@foreach(\App\Enums\Role::cases() as $r)<option value="{{ $r->value }}" @selected(request('role') === $r->value)>{{ $r->label() }}</option>@endforeach</select></div>
    <div><label for="f_status">Status</label><select id="f_status" name="status"><option value="">All</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select></div>
</x-list-filters>
<div class="card table-wrap"><table>
    <thead><tr><th>Name</th><th>E-mail</th><th>Role</th><th>Phone</th><th>Status</th><th></th></tr></thead>
    <tbody>@forelse($users as $user)
        <tr><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ $user->role->label() }}</td><td>{{ $user->phone }}</td>
            <td>@if($user->is_active)<span class="badge approved">Active</span>@else<span class="badge muted">Inactive</span>@endif</td>
            <td class="right"><a class="btn-icon" data-modal href="{{ route('users.edit', $user) }}" title="Edit {{ $user->name }}" aria-label="Edit {{ $user->name }}"><i class="fas fa-pen"></i></a></td></tr>
    @empty <tr><td colspan="6" class="empty">No users{{ $list->isFiltered() ? ' match these filters' : '' }}.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $users->links() }}</div>
@endsection
