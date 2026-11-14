@extends('layouts.app')
@section('title', $title)
@section('content')
@php
    // Human-readable summary of the active filters (screen and print).
    $context = [];
    if (request()->filled('store_id') && ($s = $stores->firstWhere('id', (int) request('store_id')))) { $context[] = ['Store', $s->code.' - '.$s->name]; }
    if (request()->filled('project_id') && ($p = $projects->firstWhere('id', (int) request('project_id')))) { $context[] = ['Project', $p->code.' - '.$p->name]; }
    if (request()->filled('item_id') && ($it = $items->firstWhere('id', (int) request('item_id')))) { $context[] = ['Item', $it->name]; }
    if (request()->filled('type')) { $context[] = ['Type', str_replace('_', ' ', request('type'))]; }
    if (in_array('dates', $filters ?? [])) {
        $periodKey = \App\Support\ListPage::periodKeyFor(request());
        $context[] = ['Period', $periodKey === 'custom'
            ? (request('from') ?: 'start').' to '.(request('to') ?: 'today')
            : (\App\Support\ListPage::PERIODS[$periodKey] ?? 'All time')];
    }
    $warnPct = \App\Services\ProjectCostService::WARN_PCT;
    $badgeTone = function ($v) {
        $u = strtoupper((string) $v);
        return match (true) {
            in_array($u, ['LOW', 'OVER'], true) => 'rejected',
            in_array($u, ['WARN', 'ON_HOLD', 'ON HOLD'], true) => 'pending',
            in_array($u, ['ACTIVE', 'OPEN'], true) => 'approved',
            in_array($u, ['COMPLETED', 'CLOSED', 'CANCELLED', 'ARCHIVED'], true) => 'muted',
            str_contains($u, 'PARTIAL') => 'partial',
            str_contains($u, 'APPROVED') => 'approved',
            default => 'pending',
        };
    };
    $rowCount = $rows->count();
@endphp

<div class="report-head">
    <a class="report-back no-print" href="{{ route('reports.index') }}"><i class="fas fa-arrow-left" aria-hidden="true"></i> All reports</a>
    <div class="report-head-main">
        <span class="report-head-icon no-print"><i class="fas {{ $icon }}" aria-hidden="true"></i></span>
        <div class="report-head-text">
            <p class="print-only report-print-company">{{ $companyName }}</p>
            <h1>{{ $title }}</h1>
            @if($description)<p class="report-desc">{{ $description }}</p>@endif
        </div>
        <div class="report-export actions" role="group" aria-label="Print or export">
            <button type="button" class="btn secondary small" data-print><i class="fas fa-print" aria-hidden="true"></i> Print</button>
            <a class="btn secondary small" href="{{ request()->fullUrlWithQuery(['format' => 'xlsx']) }}"><i class="fas fa-file-excel" aria-hidden="true"></i> Excel</a>
            <a class="btn secondary small" href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}"><i class="fas fa-file-pdf" aria-hidden="true"></i> PDF</a>
            <a class="btn secondary small" href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}"><i class="fas fa-file-csv" aria-hidden="true"></i> CSV</a>
        </div>
    </div>
    <p class="report-context">
        @forelse($context as [$label, $value])<span class="report-chip"><b>{{ $label }}:</b> {{ $value }}</span>@empty<span class="report-chip"><b>Filters:</b> none</span>@endforelse
        <span class="print-only report-printed">Printed {{ now()->format('d M Y H:i') }} by {{ auth()->user()->name }}</span>
    </p>
</div>

@if($filters)@include('partials.filters', ['fields' => $filters])@endif

@if($kpis)<div class="report-kpis"><x-stats :cards="$kpis" /></div>@endif

@if($note)<div class="report-note"><i class="fas fa-circle-info" aria-hidden="true"></i><p>{{ $note }}</p></div>@endif

<div class="card report-table-card">
    <div class="report-table-bar">
        <span><strong>{{ number_format($rowCount) }}</strong> {{ \Illuminate\Support\Str::plural('row', $rowCount) }}</span>
        @if($money)<span class="muted">Amounts in {{ currency() }}</span>@endif
    </div>
    <div class="report-scroll" data-pager-anchor>
        <table class="data-table report-table" data-paginate="25">
            <thead><tr>@foreach($headers as $i => $h)<th scope="col" @class(['num' => in_array($i, $numeric) || in_array($i, $percentCols)])>{{ $h }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse($rows as $r)
                <tr>@foreach($r as $i => $v)
                    @if(in_array($i, $numeric))
                        <td @class(['num', 'neg' => is_numeric($v) && $v < 0])>{{ is_numeric($v) ? (in_array($i, $money) ? ugx($v) : qty($v)) : $v }}</td>
                    @elseif(in_array($i, $percentCols))
                        @php($pct = $v === '' || $v === null ? null : (float) rtrim($v, '%'))
                        <td class="num">@if($pct === null)<span class="muted">-</span>@else
                            <span class="report-pct"><span class="report-bar" aria-hidden="true"><span @class(['report-bar-fill', 'over' => $pct > 100, 'warn' => $pct >= $warnPct && $pct <= 100]) style="width: {{ min(100, max(0, $pct)) }}%"></span></span><span>{{ $v }}</span></span>
                        @endif</td>
                    @elseif(in_array($i, $badgeCols))
                        <td>@if($v !== '' && $v !== null)<span class="badge {{ $badgeTone($v) }}">{{ $v === strtolower($v) ? ucfirst(str_replace('_', ' ', $v)) : $v }}</span>@endif</td>
                    @else
                        <td>{{ $v }}</td>
                    @endif
                @endforeach</tr>
            @empty
                <tr><td colspan="{{ count($headers) }}" class="empty report-empty">
                    <i class="fas fa-magnifying-glass-chart" aria-hidden="true"></i>
                    <strong>No data for this selection.</strong>
                    @if($filters)<span>Try a wider period or <a href="{{ url()->current() }}">reset the filters</a>.</span>@endif
                </td></tr>
            @endforelse
            </tbody>
            @if($sum && $rowCount)<tfoot><tr>@foreach($headers as $i => $h)<td @class(['num' => in_array($i, $numeric), 'neg' => isset($sum[$i]) && $sum[$i] < 0])>{{ $i === 0 ? 'Total' : (isset($sum[$i]) ? ugx($sum[$i]) : '') }}</td>@endforeach</tr></tfoot>@endif
        </table>
    </div>
</div>
@endsection
