@extends('layouts.app')
@section('title', 'Users')
@section('content')
<div class="page-head"><h1>Users</h1><div class="actions"><a class="btn secondary" href="{{ route('users.roles') }}"><i class="fas fa-user-tag"></i> Manage roles</a><a class="btn" data-modal href="{{ route('users.create') }}"><i class="fas fa-plus"></i> Add user</a></div></div>
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
@php($bulk = auth()->user()->can('manage-users'))
<div data-bulk-scope="users">
@if($bulk)<x-bulk-bar :action="route('users.bulk')" scope="users"><button class="btn small" name="action" value="activate" data-confirm="Activate the selected accounts?" data-confirm-ok="Yes, activate"><i class="fas fa-user-check"></i> Activate</button><button class="btn small danger" name="action" value="deactivate" data-confirm="Deactivate the selected accounts? They are signed out and cannot sign in. Accounts are never deleted, so their names stay on documents and the audit log." data-confirm-ok="Yes, deactivate"><i class="fas fa-user-slash"></i> Deactivate</button></x-bulk-bar>@endif
<div class="card table-wrap"><table>
    <thead><tr>@if($bulk)<th class="check-col"><input type="checkbox" data-check-all aria-label="Select all on this page"></th>@endif<th>Name</th><th>E-mail</th><th>Role</th><th>Phone</th><th class="num">Projects</th><th>Status</th><th></th></tr></thead>
    <tbody>@forelse($users as $user)
        <tr>@if($bulk)<td class="check-col"><input type="checkbox" value="{{ $user->id }}" data-row-check aria-label="Select {{ $user->name }}"></td>@endif<td>{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ $user->roleLabel() }}</td><td>{{ $user->phone }}</td>
            <td class="num">@if($user->projects_count){{ $user->projects_count }}@elseif(in_array($user->role, [\App\Enums\Role::Staff, \App\Enums\Role::ProjectManager], true))<span class="badge pending" title="Cannot see or request anything until assigned">None</span>@else<span class="muted">-</span>@endif</td>            <td>@if($user->is_active)<span class="badge approved">Active</span>@else<span class="badge muted">Inactive</span>@endif</td>
            <td class="right"><a class="btn-icon" data-modal href="{{ route('users.edit', $user) }}" title="Edit {{ $user->name }}" aria-label="Edit {{ $user->name }}"><i class="fas fa-pen"></i></a></td></tr>
    @empty <tr><td colspan="{{ 7 + ($bulk ? 1 : 0) }}">No users{{ $list->isFiltered() ? ' match these filters' : '' }}.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $users->links() }}</div>
</div>
@endsection
