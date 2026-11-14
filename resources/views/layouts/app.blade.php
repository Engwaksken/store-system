<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ $companyName }}</title>
    @include('partials.head-assets')
</head>
<body>
@php
    $u = auth()->user();
    $is = fn (...$patterns) => request()->routeIs(...$patterns);
@endphp
<div class="shell">
    <nav class="sidebar" aria-label="Main">
        <a href="{{ route('dashboard') }}" class="brand">@if($logoPath)<img src="{{ asset('storage/'.$logoPath) }}" alt="" class="brand-logo">@else<i class="fas fa-helmet-safety brand-icon" aria-hidden="true"></i>@endif<span>{{ $companyName }}<small>{{ $appSubtitle }}</small></span></a>
        <a href="{{ route('dashboard') }}" @class(['nav-link', 'active' => $is('dashboard')])><span><i class="fas fa-gauge-high fa-fw"></i> Dashboard</span></a>

        <x-nav-group id="projects" icon="fa-diagram-project" label="Projects" :open="$is('projects.*')">
            <a href="{{ route('projects.index') }}" @class(['nav-link', 'active' => $is('projects.*')])><span><i class="fas fa-folder-open fa-fw"></i> Projects</span></a>
        </x-nav-group>

        <x-nav-group id="materials" icon="fa-truck-ramp-box" label="Materials" :open="$is('requests.*', 'approvals.*', 'issues.*')" :count="$approvalCount + $issueQueueCount">
            @can('create-request')
                <a data-modal href="{{ route('requests.create') }}" @class(['nav-link', 'active' => $is('requests.create')])><span><i class="fas fa-file-circle-plus fa-fw"></i> New request</span></a>
            @endcan
            <a href="{{ route('requests.index') }}" @class(['nav-link', 'active' => $is('requests.index', 'requests.show', 'requests.edit')])><span><i class="fas fa-list-check fa-fw"></i> Requests</span></a>
            @can('approve-any')
                <a href="{{ route('approvals.index') }}" @class(['nav-link', 'active' => $is('approvals.*')])><span><i class="fas fa-stamp fa-fw"></i> Approvals</span>@if($approvalCount)<span class="count">{{ $approvalCount }}</span>@endif</a>
            @endcan
            <a href="{{ route('issues.index') }}" @class(['nav-link', 'active' => $is('issues.*')])><span><i class="fas fa-dolly fa-fw"></i> Issues &amp; vouchers</span>@if($issueQueueCount)<span class="count">{{ $issueQueueCount }}</span>@endif</a>
        </x-nav-group>

        <x-nav-group id="store" icon="fa-warehouse" label="Store" :open="$is('items.*', 'stores.*', 'receipts.*', 'adjustments.*', 'transfers.*', 'returns.*', 'counts.*', 'catalog.*')" :count="$transferInboxCount">
            <a href="{{ route('items.index') }}" @class(['nav-link', 'active' => $is('items.*')])><span><i class="fas fa-boxes-stacked fa-fw"></i> Stock &amp; items</span></a>
            @can('viewAny', App\Models\Store::class)
                <a href="{{ route('stores.index') }}" @class(['nav-link', 'active' => $is('stores.*')])><span><i class="fas fa-store fa-fw"></i> Stores</span></a>
            @endcan
            @can('view-stock-ledger')
                <a href="{{ route('receipts.index') }}" @class(['nav-link', 'active' => $is('receipts.*', 'adjustments.*')])><span><i class="fas fa-inbox fa-fw"></i> Receipts</span></a>
            @endcan
            @can('viewAny', App\Models\StockTransfer::class)
                <a href="{{ route('transfers.index') }}" @class(['nav-link', 'active' => $is('transfers.*')])><span><i class="fas fa-right-left fa-fw"></i> Transfers</span>@if($transferInboxCount)<span class="count">{{ $transferInboxCount }}</span>@endif</a>
            @endcan
            @can('view-returns')
                <a href="{{ route('returns.index') }}" @class(['nav-link', 'active' => $is('returns.*')])><span><i class="fas fa-rotate-left fa-fw"></i> Returns</span></a>
            @endcan
            @can('view-counts')
                <a href="{{ route('counts.index') }}" @class(['nav-link', 'active' => $is('counts.*')])><span><i class="fas fa-clipboard-check fa-fw"></i> Stock counts</span></a>
            @endcan
            @can('manage-catalog')
                <a href="{{ route('catalog.setup') }}" @class(['nav-link', 'active' => $is('catalog.*')])><span><i class="fas fa-tags fa-fw"></i> Catalog setup</span></a>
            @endcan
        </x-nav-group>

        @can('view-purchasing')
            <x-nav-group id="procurement" icon="fa-cart-shopping" label="Procurement" :open="$is('prs.*', 'pos.*', 'suppliers.*', 'invoices.*')" :count="$purchaseApprovalCount">
                <a href="{{ route('prs.index') }}" @class(['nav-link', 'active' => $is('prs.*')])><span><i class="fas fa-file-signature fa-fw"></i> Purchase requisitions</span>@if($purchaseApprovalCount)<span class="count">{{ $purchaseApprovalCount }}</span>@endif</a>
                <a href="{{ route('pos.index') }}" @class(['nav-link', 'active' => $is('pos.*')])><span><i class="fas fa-file-invoice fa-fw"></i> Purchase orders</span></a>
                @can('view-suppliers')<a href="{{ route('suppliers.index') }}" @class(['nav-link', 'active' => $is('suppliers.*')])><span><i class="fas fa-truck-field fa-fw"></i> Suppliers</span></a>@endcan
                @can('view-invoices')<a href="{{ route('invoices.index') }}" @class(['nav-link', 'active' => $is('invoices.*')])><span><i class="fas fa-file-invoice-dollar fa-fw"></i> Supplier invoices</span></a>@endcan
            </x-nav-group>
        @endcan

        <x-nav-group id="finance" icon="fa-coins" label="Finance" :open="$is('claims.*', 'expenses.*', 'cost-codes.*')" :count="$claimCount">
            <a href="{{ route('claims.index') }}" @class(['nav-link', 'active' => $is('claims.*')])><span><i class="fas fa-receipt fa-fw"></i> Expense claims</span>@if($claimCount)<span class="count">{{ $claimCount }}</span>@endif</a>
            @can('view-expenses')
                <a href="{{ route('expenses.index') }}" @class(['nav-link', 'active' => $is('expenses.*')])><span><i class="fas fa-money-bill-wave fa-fw"></i> Expenses</span></a>
            @endcan
            @can('view-cost-codes')
                <a href="{{ route('cost-codes.index') }}" @class(['nav-link', 'active' => $is('cost-codes.*')])><span><i class="fas fa-hashtag fa-fw"></i> Cost codes</span></a>
            @endcan
        </x-nav-group>

        @can('view-reports')
            <x-nav-group id="reports" icon="fa-chart-column" label="Reports" :open="$is('reports.*')">
                <a href="{{ route('reports.index') }}" @class(['nav-link', 'active' => $is('reports.*')])><span><i class="fas fa-chart-line fa-fw"></i> Reports</span></a>
            </x-nav-group>
        @endcan

        @if(Gate::allows('manage-users') || Gate::allows('view-activity'))
            <x-nav-group id="admin" icon="fa-user-shield" label="Admin" :open="$is('users.*', 'activity.*', 'settings.*')">
                @can('manage-users')<a href="{{ route('users.index') }}" @class(['nav-link', 'active' => $is('users.*')])><span><i class="fas fa-users fa-fw"></i> Users</span></a>@endcan
                @can('view-activity')<a href="{{ route('activity.index') }}" @class(['nav-link', 'active' => $is('activity.*')])><span><i class="fas fa-clock-rotate-left fa-fw"></i> Activity log</span></a>@endcan
                @can('manage-settings')<a href="{{ route('settings.edit') }}" @class(['nav-link', 'active' => $is('settings.*')])><span><i class="fas fa-gear fa-fw"></i> Settings</span></a>@endcan
            </x-nav-group>
        @endif
    </nav>
    <div class="nav-backdrop" data-nav-close></div>
    <div class="main">
        <header class="topbar">
            <button class="btn secondary small menu-toggle" type="button" data-nav-toggle aria-label="Open menu"><i class="fas fa-bars"></i></button>
            <span class="who" title="{{ $u->name }} · {{ $u->role->label() }}"><i class="fas fa-circle-user"></i> <span class="who-name">{{ $u->name }}</span><span class="who-role"> · {{ $u->role->label() }}</span></span>
            <span class="topbar-actions">
                @php($unread = $u->unreadNotifications()->count())
                <a href="{{ route('notifications.index') }}" class="btn secondary small" title="Notifications" aria-label="Notifications, {{ $unread }} unread" data-bell data-poll-url="{{ route('notifications.poll') }}" data-since="{{ now()->toIso8601String() }}"><i class="fas fa-bell"></i>@if($unread) <span class="count">{{ $unread }}</span>@endif</a>
                <a href="{{ route('password.change') }}" class="btn secondary small" title="Change password" aria-label="Change password"><i class="fas fa-key"></i><span class="btn-text"> Password</span></a>
                <form method="POST" action="{{ route('logout') }}" class="inline-form">@csrf<button class="btn secondary small" title="Sign out" aria-label="Sign out"><i class="fas fa-right-from-bracket"></i><span class="btn-text"> Sign out</span></button></form>
            </span>
        </header>
        <main class="content">
            @include('partials.flash')
            @yield('content')
        </main>
    </div>
</div>

{{-- Shared modal: forms are loaded into it (links with data-modal) or copied from a <template> (data-modal-target). --}}
<div class="modal" id="app-modal" role="dialog" aria-modal="true" aria-labelledby="app-modal-title" hidden>
    <div class="modal-dialog">
        <div class="modal-head">
            <h2 id="app-modal-title"></h2>
            <button type="button" class="modal-close" data-modal-close aria-label="Close"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body"></div>
    </div>
</div>

{{-- Confirmation modal for delete / cancel / reject buttons marked data-confirm. --}}
<div class="modal" id="confirm-modal" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" hidden>
    <div class="modal-dialog modal-sm">
        <div class="modal-head">
            <h2 id="confirm-title"><i class="fas fa-triangle-exclamation text-bad"></i> <span>Please confirm</span></h2>
            <button type="button" class="modal-close" data-modal-close aria-label="Close"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body"><p class="confirm-message"></p></div>
        <div class="modal-foot">
            <button type="button" class="btn secondary" data-modal-close><i class="fas fa-xmark"></i> No, go back</button>
            <button type="button" class="btn danger" data-confirm-ok><i class="fas fa-check"></i> <span>Yes, continue</span></button>
        </div>
    </div>
</div>

@if($errors->any() && old('_modal'))
    {{-- A modal form failed validation: reopen it with the submitted values and the errors. --}}
    <script type="application/json" id="modal-restore">@json(['target' => old('_modal'), 'old' => session()->getOldInput(), 'errors' => $errors->getMessages()])</script>
@endif
</body>
</html>
