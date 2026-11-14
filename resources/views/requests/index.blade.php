@extends('layouts.app')
@section('title', 'Material requests')
@section('content')
<div class="page-head"><h1>Material requests</h1>@can('create-request')<a class="btn" data-modal href="{{ route('requests.create') }}"><i class="fas fa-plus"></i> New request</a>@endcan</div>
<x-stats :cards="[
    ['Requests', number_format($stats['total']), 'fa-list-check', 'navy'],
    ['Awaiting approval', $stats['pending'], 'fa-hourglass-half', 'gold'],
    ['Ready to issue', $stats['ready'], 'fa-dolly'],
    ['Fully issued', $stats['done'], 'fa-circle-check'],
]" />
<x-list-filters :list="$list">
    <div><label for="f_status">Status</label><select id="f_status" name="status"><option value="">All statuses</option>@foreach(\App\Enums\RequestStatus::cases() as $s)<option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>@endforeach</select></div>
</x-list-filters>
<div class="card table-wrap"><table>
    <thead><tr><th>Request</th><th>Project</th><th>Requester</th><th>Purpose</th><th>Raised</th><th>Needed</th><th>Status</th></tr></thead>
    <tbody>@forelse($requests as $r)
        <tr><td><a href="{{ route('requests.show', $r) }}">{{ $r->request_no }}</a>@if($r->version > 1) <small class="muted">v{{ $r->version }}</small>@endif</td><td>{{ $r->project->code }}</td><td>{{ $r->requester->name }}</td>
            <td>{{ \Illuminate\Support\Str::limit($r->purpose, 50) }}</td><td class="nowrap">{{ $r->created_at->format('d M Y') }}</td><td>{{ $r->needed_on?->format('d M') }}</td><td>@include('partials.status', ['status' => $r->status])</td></tr>
    @empty <tr><td colspan="7" class="empty">No requests{{ $list->isFiltered() ? ' match these filters' : '' }}.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $requests->links() }}</div>
@endsection
