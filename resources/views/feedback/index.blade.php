@extends('layouts.app')
@section('title', 'Feedback')
@section('content')
<div class="page-head"><div><h1>Feedback</h1><p>{{ $manage ? 'Comments, problems and ideas from everyone using the web and mobile apps. Reply to let the sender know what happens next.' : 'Tell the administrators what works, what does not, and what you would like. You are told when they reply.' }}</p></div>
    <div class="actions"><a class="btn" href="{{ route('feedback.create') }}"><i class="fas fa-comment-medical"></i> Send feedback</a></div></div>
<x-stats :cards="[
    ['Feedback', $stats['total'], 'fa-comments', 'navy'],
    ['New', $stats['new'], 'fa-envelope', $stats['new'] ? 'gold' : ''],
    ['In review', $stats['in_review'], 'fa-magnifying-glass'],
    ['Resolved / closed', $stats['resolved'], 'fa-circle-check'],
]" />
<x-list-filters :list="$list">
    <div><label for="f_status">Status</label><select id="f_status" name="status"><option value="">All</option>@foreach(\App\Models\Feedback::STATUSES as $k => $label)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $label }}</option>@endforeach</select></div>
    <div><label for="f_category">Category</label><select id="f_category" name="category"><option value="">All</option>@foreach(\App\Models\Feedback::CATEGORIES as $k => $label)<option value="{{ $k }}" @selected(request('category') === $k)>{{ $label }}</option>@endforeach</select></div>
</x-list-filters>
@php($bulk = auth()->user()->can('manage-feedback'))
<div data-bulk-scope="feedback">
@if($bulk)<x-bulk-bar :action="route('feedback.bulk')" scope="feedback"><button class="btn small danger" name="action" value="delete" data-confirm="Delete the selected feedback? This cannot be undone." data-confirm-ok="Yes, delete"><i class="fas fa-trash-can"></i> Delete</button></x-bulk-bar>@endif
<div class="card table-wrap"><table>
    <thead><tr>@if($bulk)<th class="check-col"><input type="checkbox" data-check-all aria-label="Select all on this page"></th>@endif<th>Reference</th><th>Sent</th>@if($manage)<th>From</th>@endif<th>Category</th><th>Subject</th><th>Rating</th><th>Status</th><th></th></tr></thead>
    <tbody>@forelse($items as $f)
        <tr>@if($bulk)<td class="check-col"><input type="checkbox" value="{{ $f->id }}" data-row-check aria-label="Select {{ $f->reference() }}"></td>@endif<td class="nowrap">{{ $f->reference() }}</td><td class="nowrap" title="{{ $f->created_at->format('d M Y H:i') }}">{{ $f->created_at->diffForHumans() }}</td>
            @if($manage)<td>{{ $f->user?->name }}</td>@endif
            <td>{{ $f->categoryLabel() }}</td>
            <td><a href="{{ route('feedback.show', $f) }}">{{ $f->subject }}</a> @if($f->source === 'mobile')<i class="fas fa-mobile-screen muted" title="Sent from the mobile app" aria-label="mobile app"></i>@endif</td>
            <td class="nowrap">@if($f->rating)<span title="{{ $f->rating }} of 5">{{ str_repeat('★', $f->rating) }}<span class="muted">{{ str_repeat('☆', 5 - $f->rating) }}</span></span>@else<span class="muted">-</span>@endif</td>
            <td><span class="badge {{ $f->statusTone() }}">{{ $f->statusLabel() }}</span>@if($f->response) <i class="fas fa-reply muted" title="Replied" aria-label="replied"></i>@endif</td>
            <td class="right"><a class="btn small secondary" href="{{ route('feedback.show', $f) }}">Open</a></td></tr>
    @empty <tr><td colspan="{{ ($manage ? 8 : 7) + ($bulk ? 1 : 0) }}">{{ $list->isFiltered() ? 'No feedback matches these filters.' : 'No feedback yet.' }}</td></tr> @endforelse</tbody>
</table></div>
<div class="pagination">{{ $items->links() }}</div>
</div>
@endsection
