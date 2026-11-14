{{-- expects: $fields (array of 'store','project','item','type','dates'), $stores, $projects, $items --}}
<form method="GET" class="card filters list-filters">
    <div class="filter-grid">
        @if(in_array('store', $fields))
            <div><label>Branch / store</label><select name="store_id"><option value="">All branches</option>
                @foreach($stores as $s)<option value="{{ $s->id }}" @selected(request('store_id') == $s->id)>{{ $s->code }} - {{ $s->name }}</option>@endforeach
            </select></div>
        @endif
        @if(in_array('project', $fields))
            <div><label>Project</label><select name="project_id"><option value="">All</option>
                @foreach($projects as $p)<option value="{{ $p->id }}" @selected(request('project_id') == $p->id)>{{ $p->code }} - {{ $p->name }}</option>@endforeach
            </select></div>
        @endif
        @if(in_array('item', $fields))
            <div><label>Item</label><select name="item_id"><option value="">All</option>
                @foreach($items as $i)<option value="{{ $i->id }}" @selected(request('item_id') == $i->id)>{{ $i->name }}</option>@endforeach
            </select></div>
        @endif
        @if(in_array('type', $fields))
            <div><label>Movement type</label><select name="type"><option value="">All</option>
                @foreach(['opening','receipt','issue','return','adjustment','transfer_out','transfer_in','transfer_cancel'] as $t)<option value="{{ $t }}" @selected(request('type') === $t)>{{ str_replace('_', ' ', $t) }}</option>@endforeach
            </select></div>
        @endif
        @if(in_array('dates', $fields))
            @php($period = \App\Support\ListPage::periodKeyFor(request()))
            <div><label for="r_period">Period</label><select id="r_period" name="period" data-period-select>
                @foreach(\App\Support\ListPage::PERIODS as $k => $label)<option value="{{ $k }}" @selected($period === $k)>{{ $label }}</option>@endforeach</select></div>
            <div class="period-dates" data-period-dates @if($period !== 'custom') hidden @endif>
                <div><label for="r_from">From</label><input id="r_from" type="date" name="from" value="{{ request('from') }}"></div>
                <div><label for="r_to">To</label><input id="r_to" type="date" name="to" value="{{ request('to') }}"></div>
            </div>
        @endif
    </div>
    <div class="actions" style="margin-top:.75rem"><button class="btn small">Apply</button><a class="btn secondary small" href="{{ url()->current() }}">Reset</a></div>
</form>
