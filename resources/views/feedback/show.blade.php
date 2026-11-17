@extends('layouts.app')
@section('title', 'Feedback '.$feedback->reference())
@section('content')
<div class="page-head"><div><h1>{{ $feedback->subject }} <span class="badge {{ $feedback->statusTone() }}">{{ $feedback->statusLabel() }}</span></h1>
    <p>{{ $feedback->reference() }} · {{ $feedback->categoryLabel() }} · sent {{ $feedback->created_at->format('d M Y H:i') }}</p></div>
    <a class="btn secondary" href="{{ route('feedback.index') }}">Back to feedback</a></div>
<div class="card" style="max-width:860px;margin-bottom:1rem">
    <dl class="meta">
        <dt>From</dt><dd>{{ $feedback->user?->name }}@if($manage && $feedback->user) <span class="muted">({{ $feedback->user->email }}, {{ $feedback->user->roleLabel() }})</span>@endif</dd>
        <dt>Category</dt><dd>{{ $feedback->categoryLabel() }}</dd>
        <dt>Rating</dt><dd>@if($feedback->rating){{ str_repeat('★', $feedback->rating) }}<span class="muted">{{ str_repeat('☆', 5 - $feedback->rating) }}</span> ({{ $feedback->rating }} of 5)@else - @endif</dd>
        <dt>Sent from</dt><dd>{{ $feedback->source === 'mobile' ? 'Mobile app' : 'Web app' }}@if($manage && ($feedback->app_version || $feedback->device)) <span class="muted">{{ collect([$feedback->app_version ? 'v'.$feedback->app_version : null, $feedback->device])->filter()->implode(' · ') }}</span>@endif</dd>
        <dt>Details</dt><dd style="white-space:pre-line">{{ $feedback->message }}</dd>
    </dl>
</div>
<div class="card" style="max-width:860px;margin-bottom:1rem"><h2><i class="fas fa-reply"></i> Reply</h2>
    @if($feedback->response)
        <p style="white-space:pre-line">{{ $feedback->response }}</p>
        <p class="hint">{{ $feedback->responder?->name ?? 'An administrator' }} · {{ $feedback->responded_at?->format('d M Y H:i') }}</p>
    @else
        <p class="muted">No reply yet.</p>
    @endif
</div>
@if($manage)
<form method="POST" action="{{ route('feedback.respond', $feedback) }}" class="card" style="max-width:860px">@csrf @method('PUT')
    <h2>Update</h2>
    <div class="form-grid">
        <div class="field"><label for="fb_status">Status</label><select id="fb_status" name="status" required>
            @foreach(\App\Models\Feedback::STATUSES as $k => $label)<option value="{{ $k }}" @selected(old('status', $feedback->status) === $k)>{{ $label }}</option>@endforeach
        </select>@error('status')<div class="err">{{ $message }}</div>@enderror</div>
    </div>
    <div class="field"><label for="fb_response">Reply to {{ $feedback->user?->name ?? 'the sender' }} (optional)</label><textarea id="fb_response" name="response" rows="5" maxlength="5000">{{ old('response', $feedback->response) }}</textarea>
        <div class="hint">The sender is notified when you reply or change the status.</div>@error('response')<div class="err">{{ $message }}</div>@enderror</div>
    <button class="btn"><i class="fas fa-floppy-disk"></i> Save</button>
</form>
@endif
@endsection
