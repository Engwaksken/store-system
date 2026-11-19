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
@php($bulk = auth()->user()->can('manage-projects'))
<div data-bulk-scope="projects">
@if($bulk)<x-bulk-bar :action="route('projects.bulk')" scope="projects"><button class="btn small danger" name="action" value="delete" data-confirm="Delete the selected projects? Projects with requests, stock, money, purchasing, plans or a site store are skipped and listed (set those to Cancelled instead)." data-confirm-ok="Yes, delete"><i class="fas fa-trash-can"></i> Delete</button></x-bulk-bar>@endif
<div class="card table-wrap"><table>
    <thead><tr>@if($bulk)<th class="check-col"><input type="checkbox" data-check-all aria-label="Select all on this page"></th>@endif<th>Code</th><th>Name</th><th>Client / location</th><th>Manager</th><th>Team</th><th>Status</th>@can('view-finance-reports')<th class="num">Budget ({{ currency() }})</th>@endcan @can('manage-projects')<th></th>@endcan</tr></thead>
    <tbody>@forelse($projects as $p)
        <tr>@if($bulk)<td class="check-col"><input type="checkbox" value="{{ $p->id }}" data-row-check aria-label="Select {{ $p->code }}"></td>@endif<td><a href="{{ route('projects.show', $p) }}">{{ $p->code }}</a></td><td>{{ $p->name }}</td><td>{{ $p->client }}<br><small class="muted">{{ $p->location }}</small></td>
            <td>{{ $p->manager?->name ?? '-' }}</td><td>{{ $p->members_count }}</td><td><span class="badge muted">{{ $p->status }}</span></td>
            @can('view-finance-reports')<td class="num">@can('view-project-finance', $p){{ ugx($p->budget) }}@else - @endcan</td>@endcan
            @can('manage-projects')<td class="right"><a class="btn-icon" data-modal href="{{ route('projects.edit', $p) }}" title="Edit {{ $p->code }}" aria-label="Edit {{ $p->code }}"><i class="fas fa-pen"></i></a></td>@endcan</tr>
    @empty <tr><td colspan="{{ 8 + ($bulk ? 1 : 0) }}">No projects{{ $list->isFiltered() ? ' match these filters' : '' }}.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $projects->links() }}</div>
</div>
@endsection
