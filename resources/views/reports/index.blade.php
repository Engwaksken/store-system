@extends('layouts.app')
@section('title', 'Reports')
@section('content')
<div class="page-head"><div><h1>Reports</h1><p>Every report can be printed or exported to Excel, PDF or CSV. Rows are limited to what you are allowed to see.</p></div></div>

@foreach($groups as $key => $group)
<section class="report-group" aria-labelledby="rg-{{ $key }}">
    <header class="report-group-head">
        <span class="report-group-icon"><i class="fas {{ $group['icon'] }}" aria-hidden="true"></i></span>
        <div><h2 id="rg-{{ $key }}">{{ $group['name'] }}</h2><p>{{ $group['description'] }}</p></div>
    </header>
    <div class="report-grid">
        @foreach($group['reports'] as $r)
            <a class="report-card" href="{{ route($r['route']) }}">
                <span class="report-card-icon"><i class="fas {{ $r['icon'] }}" aria-hidden="true"></i></span>
                <span class="report-card-body">
                    <strong>{{ $r['name'] }}</strong>
                    <span>{{ $r['description'] }}</span>
                </span>
                <i class="fas fa-arrow-right report-card-go" aria-hidden="true"></i>
            </a>
        @endforeach
    </div>
</section>
@endforeach
@endsection
