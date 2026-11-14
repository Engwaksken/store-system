@extends('layouts.app')
@section('title', 'Users')
@section('content')
<div class="page-head"><h1>Users</h1><a class="btn" data-modal href="{{ route('users.create') }}"><i class="fas fa-plus"></i> Add user</a></div>
<div class="card table-wrap"><table>
    <thead><tr><th>Name</th><th>E-mail</th><th>Role</th><th>Phone</th><th>Status</th><th></th></tr></thead>
    <tbody>@foreach($users as $user)
        <tr><td>{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ $user->role->label() }}</td><td>{{ $user->phone }}</td>
            <td>@if($user->is_active)<span class="badge approved">Active</span>@else<span class="badge muted">Inactive</span>@endif</td>
            <td class="right"><a class="btn-icon" data-modal href="{{ route('users.edit', $user) }}" title="Edit {{ $user->name }}" aria-label="Edit {{ $user->name }}"><i class="fas fa-pen"></i></a></td></tr>
    @endforeach</tbody>
</table></div>
@endsection
