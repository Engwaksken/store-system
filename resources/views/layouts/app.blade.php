<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ $companyName }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=1">
</head>
<body>
@php($u = auth()->user())
<div class="shell">
    <nav class="sidebar" aria-label="Main">
        <div class="brand">{{ $companyName }}<small>STORE &amp; PROJECTS</small></div>
        <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')])>Dashboard</a>

        <div class="nav-group">Projects</div>
        <a href="{{ route('projects.index') }}" @class(['active' => request()->routeIs('projects.*')])>Projects</a>

        <div class="nav-group">Materials</div>
        @can('create-request')
            <a href="{{ route('requests.create') }}" @class(['active' => request()->routeIs('requests.create')])>New request</a>
        @endcan
        <a href="{{ route('requests.index') }}" @class(['active' => request()->routeIs('requests.index', 'requests.show', 'requests.edit')])>Requests</a>
        @can('approve-any')
            <a href="{{ route('approvals.index') }}" @class(['active' => request()->routeIs('approvals.*')])>Approvals @if($approvalCount)<span class="count">{{ $approvalCount }}</span>@endif</a>
        @endcan
        <a href="{{ route('issues.index') }}" @class(['active' => request()->routeIs('issues.*')])>Issues &amp; vouchers @if($issueQueueCount)<span class="count">{{ $issueQueueCount }}</span>@endif</a>

        <div class="nav-group">Store</div>
        <a href="{{ route('items.index') }}" @class(['active' => request()->routeIs('items.*')])>Stock &amp; items</a>
        @can('viewAny', App\Models\Store::class)
            <a href="{{ route('stores.index') }}" @class(['active' => request()->routeIs('stores.*')])>Stores</a>
        @endcan
        @can('view-stock-ledger')
            <a href="{{ route('receipts.index') }}" @class(['active' => request()->routeIs('receipts.*', 'adjustments.*')])>Receipts</a>
        @endcan
        @can('viewAny', App\Models\StockTransfer::class)
            <a href="{{ route('transfers.index') }}" @class(['active' => request()->routeIs('transfers.*')])>Transfers @if($transferInboxCount)<span class="count">{{ $transferInboxCount }}</span>@endif</a>
        @endcan
        @can('view-returns')
            <a href="{{ route('returns.index') }}" @class(['active' => request()->routeIs('returns.*')])>Returns</a>
        @endcan
        @can('view-counts')
            <a href="{{ route('counts.index') }}" @class(['active' => request()->routeIs('counts.*')])>Stock counts</a>
        @endcan
        @can('manage-catalog')
            <a href="{{ route('catalog.setup') }}" @class(['active' => request()->routeIs('catalog.*')])>Categories &amp; units</a>
        @endcan

        @can('view-expenses')
            <div class="nav-group">Finance</div>
            <a href="{{ route('expenses.index') }}" @class(['active' => request()->routeIs('expenses.*')])>Expenses</a>
        @endcan

        @can('view-reports')
            <div class="nav-group">Reports</div>
            <a href="{{ route('reports.index') }}" @class(['active' => request()->routeIs('reports.*')])>Reports</a>
        @endcan

        @if(Gate::allows('manage-users') || Gate::allows('view-activity'))
            <div class="nav-group">Admin</div>
            @can('manage-users')<a href="{{ route('users.index') }}" @class(['active' => request()->routeIs('users.*')])>Users</a>@endcan
            @can('view-activity')<a href="{{ route('activity.index') }}" @class(['active' => request()->routeIs('activity.*')])>Activity log</a>@endcan
            @can('manage-settings')<a href="{{ route('settings.edit') }}" @class(['active' => request()->routeIs('settings.*')])>Settings</a>@endcan
        @endif
    </nav>
    <div class="main">
        <header class="topbar">
            <button class="btn secondary small menu-toggle" type="button" onclick="document.body.classList.toggle('nav-open')">☰ Menu</button>
            <span class="who">{{ $u->name }} · {{ $u->role->label() }}</span>
            <span class="actions">
                <a href="{{ route('password.change') }}" class="btn secondary small">Password</a>
                <form method="POST" action="{{ route('logout') }}" class="inline-form">@csrf<button class="btn secondary small">Sign out</button></form>
            </span>
        </header>
        <main class="content">
            @include('partials.flash')
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
