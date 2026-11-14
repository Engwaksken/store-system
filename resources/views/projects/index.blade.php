@extends('layouts.app')
@section('title', 'Projects')
@section('content')
<div class="page-head"><div><h1>Projects</h1><p>Only projects you are allowed to see are listed.</p></div>
    @can('manage-projects')<a class="btn" data-modal href="{{ route('projects.create') }}"><i class="fas fa-plus"></i> New project</a>@endcan</div>
<x-stats :cards="[
    ['Projects', $stats['total'], 'fa-diagram-project', 'navy'],
    ['Active', $stats['active'], 'fa-person-digging', '', route('projects.index', ['status' => 'active'])],
    ['On hold', $stats['on_hold'], 'fa-circle-pause', 'gold', route('projects.index', ['status' => 'on_hold'])],
    ['Completed', $stats['completed'], 'fa-flag-checkered', '', route('projects.index', ['status' => 'completed'])],
]" />
<x-list-filters :list="$list">
    <div><label for="f_status">Status</label><select id="f_status" name="status"><option value="">All statuses</option>@foreach(\App\Models\Project::STATUSES as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></div>
</x-list-filters>
<div class="card table-wrap"><table>
    <thead><tr><th>Code</th><th>Name</th><th>Client / location</th><th>Manager</th><th>Team</th><th>Status</th>@can('view-finance-reports')<th class="num">Budget ({{ currency() }})</th>@endcan @can('manage-projects')<th></th>@endcan</tr></thead>
    <tbody>@forelse($projects as $p)
        <tr><td><a href="{{ route('projects.show', $p) }}">{{ $p->code }}</a></td><td>{{ $p->name }}</td><td>{{ $p->client }}<br><small class="muted">{{ $p->location }}</small></td>
            <td>{{ $p->manager?->name ?? '-' }}</td><td>{{ $p->members_count }}</td><td><span class="badge muted">{{ $p->status }}</span></td>
            @can('view-finance-reports')<td class="num">@can('view-project-finance', $p){{ ugx($p->budget) }}@else - @endcan</td>@endcan
            @can('manage-projects')<td class="right"><a class="btn-icon" data-modal href="{{ route('projects.edit', $p) }}" title="Edit {{ $p->code }}" aria-label="Edit {{ $p->code }}"><i class="fas fa-pen"></i></a></td>@endcan</tr>
    @empty <tr><td colspan="8" class="empty">No projects{{ $list->isFiltered() ? ' match these filters' : '' }}.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $projects->links() }}</div>
@endsection
