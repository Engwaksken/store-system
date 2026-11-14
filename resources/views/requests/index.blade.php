@extends('layouts.app')
@section('title', 'Material requests')
@section('content')
<div class="page-head"><h1>Material requests</h1>@can('create-request')<a class="btn" href="{{ route('requests.create') }}"><i class="fas fa-plus"></i> New request</a>@endcan</div>
<form method="GET" class="card filters"><div class="grid grid-3">
    <div><label>Project</label><select name="project_id"><option value="">All</option>@foreach($projects as $p)<option value="{{ $p->id }}" @selected(request('project_id') == $p->id)>{{ $p->code }} - {{ $p->name }}</option>@endforeach</select></div>
    <div><label>Status</label><select name="status"><option value="">All</option>@foreach(\App\Enums\RequestStatus::cases() as $s)<option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>@endforeach</select></div>
    <div><label>&nbsp;</label><button class="btn small">Filter</button></div>
</div></form>
<div class="card table-wrap"><table>
    <thead><tr><th>Request</th><th>Project</th><th>Requester</th><th>Purpose</th><th>Needed</th><th>Status</th></tr></thead>
    <tbody>@forelse($requests as $r)
        <tr><td><a href="{{ route('requests.show', $r) }}">{{ $r->request_no }}</a>@if($r->version > 1) <small class="muted">v{{ $r->version }}</small>@endif</td><td>{{ $r->project->code }}</td><td>{{ $r->requester->name }}</td>
            <td>{{ \Illuminate\Support\Str::limit($r->purpose, 50) }}</td><td>{{ $r->needed_on?->format('d M') }}</td><td>@include('partials.status', ['status' => $r->status])</td></tr>
    @empty <tr><td colspan="6" class="empty">No requests.</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $requests->links() }}</div>
@endsection
