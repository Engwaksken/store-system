@extends('layouts.app')
@section('title', 'Projects')
@section('content')
<div class="page-head"><div><h1>Projects</h1><p>Only projects you are allowed to see are listed.</p></div>
    @can('manage-projects')<a class="btn" href="{{ route('projects.create') }}">+ New project</a>@endcan</div>
<form method="GET" class="card filters"><div class="grid grid-3">
    <div><label>Search</label><input type="text" name="q" value="{{ request('q') }}" placeholder="Code or name"></div>
    <div><label>Status</label><select name="status"><option value="">All</option>@foreach(\App\Models\Project::STATUSES as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></div>
    <div><label>&nbsp;</label><button class="btn small">Filter</button></div>
</div></form>
<div class="card table-wrap"><table>
    <thead><tr><th>Code</th><th>Name</th><th>Client / location</th><th>Manager</th><th>Team</th><th>Status</th>@can('view-finance-reports')<th class="num">Budget (UGX)</th>@endcan</tr></thead>
    <tbody>@forelse($projects as $p)
        <tr><td><a href="{{ route('projects.show', $p) }}">{{ $p->code }}</a></td><td>{{ $p->name }}</td><td>{{ $p->client }}<br><small class="muted">{{ $p->location }}</small></td>
            <td>{{ $p->manager?->name ?? '—' }}</td><td>{{ $p->members_count }}</td><td><span class="badge muted">{{ $p->status }}</span></td>
            @can('view-finance-reports')<td class="num">@can('view-project-finance', $p){{ ugx($p->budget) }}@else — @endcan</td>@endcan</tr>
    @empty <tr><td colspan="7" class="empty">No projects.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $projects->links() }}</div>
@endsection
