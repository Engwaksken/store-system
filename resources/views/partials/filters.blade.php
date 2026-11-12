{{-- expects: $fields (array of 'project','item','type','dates','status'), $projects, $items --}}
<form method="GET" class="card filters">
    <div class="grid grid-4">
        @if(in_array('project', $fields))
            <div><label>Project</label><select name="project_id"><option value="">All</option>
                @foreach($projects as $p)<option value="{{ $p->id }}" @selected(request('project_id') == $p->id)>{{ $p->code }} – {{ $p->name }}</option>@endforeach
            </select></div>
        @endif
        @if(in_array('item', $fields))
            <div><label>Item</label><select name="item_id"><option value="">All</option>
                @foreach($items as $i)<option value="{{ $i->id }}" @selected(request('item_id') == $i->id)>{{ $i->name }}</option>@endforeach
            </select></div>
        @endif
        @if(in_array('type', $fields))
            <div><label>Movement type</label><select name="type"><option value="">All</option>
                @foreach(['opening','receipt','issue','adjustment'] as $t)<option @selected(request('type') === $t)>{{ $t }}</option>@endforeach
            </select></div>
        @endif
        @if(in_array('dates', $fields))
            <div><label>From</label><input type="date" name="from" value="{{ request('from') }}"></div>
            <div><label>To</label><input type="date" name="to" value="{{ request('to') }}"></div>
        @endif
    </div>
    <div class="actions" style="margin-top:.75rem"><button class="btn small">Apply</button><a class="btn secondary small" href="{{ url()->current() }}">Reset</a></div>
</form>
