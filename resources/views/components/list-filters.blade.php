@props(['list'])
{{-- Filter bar for list pages built with App\Support\ListPage: period, project, branch (store), search,
     page-specific extras in the slot, and CSV / PDF export of the filtered rows. --}}
@php
    [$from, $to] = $list->range();
    $period = $list->periodKey();
@endphp
<form method="GET" class="card filters list-filters" data-list-filters>
    <div class="filter-grid">
        @if($list->has('search'))
            <div class="filter-search"><label for="f_q">Search</label><input id="f_q" type="text" name="q" value="{{ $list->term() }}" placeholder="{{ $list->searchPlaceholder ?? 'Number, name or description' }}"></div>
        @endif
        @if($list->has('period'))
            <div><label for="f_period">{{ $list->periodLabel }}</label>
                <select id="f_period" name="period" data-period-select>
                    @foreach(\App\Support\ListPage::PERIODS as $k => $label)<option value="{{ $k }}" @selected($period === $k)>{{ $label }}</option>@endforeach
                </select></div>
            <div class="period-dates" data-period-dates @if($period !== 'custom') hidden @endif>
                <div><label for="f_from">From</label><input id="f_from" type="date" name="from" value="{{ $period === 'custom' ? $from?->toDateString() : '' }}"></div>
                <div><label for="f_to">To</label><input id="f_to" type="date" name="to" value="{{ $period === 'custom' ? $to?->toDateString() : '' }}"></div>
            </div>
        @endif
        @if($list->has('project'))
            <div><label for="f_project">Project</label><select id="f_project" name="project_id"><option value="">All projects</option>
                @foreach($list->projectOptions() as $p)<option value="{{ $p->id }}" @selected($list->projectId() === $p->id)>{{ $p->code }} - {{ $p->name }}</option>@endforeach
            </select></div>
        @endif
        @if($list->has('store'))
            <div><label for="f_store">Branch / store</label><select id="f_store" name="store_id"><option value="">All branches</option>
                @foreach($list->storeOptions() as $s)<option value="{{ $s->id }}" @selected($list->storeId() === $s->id)>{{ $s->code }} - {{ $s->name }}</option>@endforeach
            </select></div>
        @endif
        {{ $slot }}
    </div>
    <div class="filter-actions">
        <div class="actions">
            <button class="btn small"><i class="fas fa-filter"></i> Apply</button>
            @if($list->isFiltered())<a class="btn secondary small" href="{{ url()->current() }}"><i class="fas fa-rotate-left"></i> Reset</a>@endif
            @if($list->periodKey() !== '' && ($from || $to))<span class="muted filter-note"><i class="fas fa-calendar-days"></i> {{ $from?->format('d M Y') ?? 'start' }} - {{ $to?->format('d M Y') ?? 'today' }}</span>@endif
        </div>
        @if($list->has('export'))
        <div class="actions export-actions">
            <a class="btn secondary small" href="{{ $list->exportUrl('csv') }}" title="Download the filtered rows as CSV (Excel)"><i class="fas fa-file-csv"></i> CSV</a>
            <a class="btn secondary small" href="{{ $list->exportUrl('pdf') }}" title="Download the filtered rows as PDF"><i class="fas fa-file-pdf"></i> PDF</a>
        </div>
        @endif
    </div>
</form>
