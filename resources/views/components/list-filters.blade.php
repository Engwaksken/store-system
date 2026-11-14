@props(['list'])
{{-- Filter bar for list pages built with App\Support\ListPage: one row with search, period (+ custom dates),
     project, branch (store), page-specific extras (slot), then Apply / Reset and CSV / PDF export. Wraps on small screens. --}}
@php
    [$from, $to] = $list->range();
    $period = $list->periodKey();
@endphp
<form method="GET" class="list-filters filters" data-list-filters>
    <div class="filter-row">
        @if($list->has('search'))
            <div class="filter-field filter-search"><label for="f_q">Search</label>
                <div class="input-icon"><i class="fas fa-magnifying-glass"></i><input id="f_q" type="text" name="q" value="{{ $list->term() }}" placeholder="{{ $list->searchPlaceholder ?? 'Number, name or description' }}"></div></div>
        @endif
        @if($list->has('period'))
            <div class="filter-field"><label for="f_period">{{ $list->periodLabel }}</label>
                <select id="f_period" name="period" data-period-select>
                    @foreach(\App\Support\ListPage::PERIODS as $k => $label)<option value="{{ $k }}" @selected($period === $k)>{{ $label }}</option>@endforeach
                </select></div>
            <div class="period-dates" data-period-dates @if($period !== 'custom') hidden @endif>
                <div class="filter-field filter-date"><label for="f_from">From</label><input id="f_from" type="date" name="from" value="{{ $period === 'custom' ? $from?->toDateString() : '' }}"></div>
                <div class="filter-field filter-date"><label for="f_to">To</label><input id="f_to" type="date" name="to" value="{{ $period === 'custom' ? $to?->toDateString() : '' }}"></div>
            </div>
        @endif
        @if($list->has('project'))
            <div class="filter-field"><label for="f_project">Project</label><select id="f_project" name="project_id"><option value="">All projects</option>
                @foreach($list->projectOptions() as $p)<option value="{{ $p->id }}" @selected($list->projectId() === $p->id)>{{ $p->code }} - {{ $p->name }}</option>@endforeach
            </select></div>
        @endif
        @if($list->has('store'))
            <div class="filter-field"><label for="f_store">Branch</label><select id="f_store" name="store_id"><option value="">All branches</option>
                @foreach($list->storeOptions() as $s)<option value="{{ $s->id }}" @selected($list->storeId() === $s->id)>{{ $s->code }} - {{ $s->name }}</option>@endforeach
            </select></div>
        @endif
        {{ $slot }}
        <div class="filter-buttons">
            <button class="btn small" title="Apply filters"><i class="fas fa-filter"></i> Apply</button>
            @if($list->isFiltered())<a class="btn secondary small" href="{{ url()->current() }}" title="Clear all filters"><i class="fas fa-rotate-left"></i> Reset</a>@endif
            @if($list->has('export'))
                <span class="filter-divider" aria-hidden="true"></span>
                <a class="btn secondary small" href="{{ $list->exportUrl('csv') }}" title="Download the filtered rows as CSV (Excel)"><i class="fas fa-file-csv"></i> CSV</a>
                <a class="btn secondary small" href="{{ $list->exportUrl('pdf') }}" title="Download the filtered rows as PDF"><i class="fas fa-file-pdf"></i> PDF</a>
            @endif
        </div>
    </div>
    @if($list->periodKey() !== '' && ($from || $to))
        <div class="filter-summary"><i class="fas fa-calendar-days"></i> {{ $from?->format('d M Y') ?? 'start' }} - {{ $to?->format('d M Y') ?? 'today' }}</div>
    @endif
</form>
